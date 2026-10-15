<?php

declare(strict_types=1);

use App\Http\HttpApplicationRunner;
use Flint\Container\Container;
use Flint\Foundation\Application;
use Flint\Foundation\ApplicationRunException;
use Flint\Foundation\BootableServiceProvider;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function runnerBoundaryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Failed boot: cleanup must happen before the fallback response.
$application = new Application(dirname(__DIR__));
$bootFailure = new RuntimeException('Controlled boot failure.');
$events = [];
$responses = [];
$reported = [];

$application->register(
    new class ($bootFailure) implements BootableServiceProvider {
        public function __construct(
            private readonly RuntimeException $failure,
        ) {}

        public function register(Container $container): void {}

        public function boot(Container $container): void
        {
            throw $this->failure;
        }
    },
);

$application->onTerminate(
    static function () use (&$events): void {
        $events[] = 'cleanup';
    },
);

$runner = new HttpApplicationRunner(
    emit: static function (Response $response) use (
        &$events,
        &$responses,
    ): void {
        $events[] = 'emit';
        $responses[] = $response;
    },
    report: static function (Throwable $exception) use (
        &$reported,
    ): void {
        $reported[] = $exception;
    },
);

$runner->run(
    $application,
    static function (Application $running) use (&$events): Response {
        $events[] = 'handle';

        return new Response(body: 'Must not be emitted');
    },
);

runnerBoundaryCheck(
    $events === ['cleanup', 'emit'],
    'Failed boot must skip handling and clean up before fallback emission.',
);

runnerBoundaryCheck(
    count($responses) === 1,
    'Failed boot must produce exactly one emission attempt.',
);

runnerBoundaryCheck(
    $responses[0]->status() === 500
        && $responses[0]->body() === 'Internal server error'
        && $responses[0]->headers()->get('X-Content-Type-Options')
            === ['nosniff'],
    'Failed boot must produce a generic response with security headers.',
);

runnerBoundaryCheck(
    $reported === [$bootFailure],
    'Boot failure identity must be preserved.',
);

runnerBoundaryCheck(
    $application->isTerminated(),
    'Failed boot must leave the application terminated.',
);

// Failed emission and cleanup: both failures must remain available.
$application = new Application(dirname(__DIR__));
$emissionFailure = new RuntimeException('Controlled emission failure.');
$cleanupFailure = new RuntimeException('Controlled cleanup failure.');
$events = [];
$reported = [];
$emissionAttempts = 0;

$application->onTerminate(
    static function () use (&$events, $cleanupFailure): void {
        $events[] = 'cleanup';

        throw $cleanupFailure;
    },
);

$runner = new HttpApplicationRunner(
    emit: static function (Response $response) use (
        &$events,
        &$emissionAttempts,
        $emissionFailure,
    ): void {
        ++$emissionAttempts;
        $events[] = 'emit';

        throw $emissionFailure;
    },
    report: static function (Throwable $exception) use (
        &$reported,
    ): void {
        $reported[] = $exception;
    },
);

$runner->run(
    $application,
    static function (Application $running) use (&$events): Response {
        $events[] = 'handle';

        return new Response(body: 'Ready');
    },
);

runnerBoundaryCheck(
    $events === ['handle', 'emit', 'cleanup'],
    'Emission failure must still trigger cleanup.',
);

runnerBoundaryCheck(
    $emissionAttempts === 1,
    'Combined failure must not trigger a second emission.',
);

runnerBoundaryCheck(
    count($reported) === 1,
    'Combined failure must produce one report.',
);

$combined = $reported[0];

if (!$combined instanceof ApplicationRunException) {
    throw new RuntimeException(
        'Combined failure must retain execution and cleanup exceptions.',
    );
}

runnerBoundaryCheck(
    $combined->executionFailure === $emissionFailure
        && $combined->getPrevious() === $emissionFailure
        && $combined->terminationFailure === $cleanupFailure,
    'Combined failure must preserve both original exception objects.',
);

runnerBoundaryCheck(
    $application->isTerminated(),
    'Cleanup failure must still leave the application terminated.',
);

echo 'PASS: HTTP boot rejection and combined emission/cleanup failure boundaries.'
    . PHP_EOL;

<?php

declare(strict_types=1);

use App\Http\HttpApplicationRunner;
use Flint\Foundation\Application;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function httpRunnerCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['success', 'handling', 'emission', 'cleanup'] as $scenario) {
    $application = new Application(dirname(__DIR__));
    $events = [];
    $responses = [];
    $reported = [];
    $failure = new RuntimeException('Controlled failure.');

    $application->onTerminate(
        static function () use (
            &$events,
            $scenario,
            $failure,
        ): void {
            $events[] = 'cleanup';

            if ($scenario === 'cleanup') {
                throw $failure;
            }
        },
    );

    $runner = new HttpApplicationRunner(
        emit: static function (Response $response) use (
            &$events,
            &$responses,
            $scenario,
            $failure,
        ): void {
            $events[] = 'emit';
            $responses[] = $response;

            if ($scenario === 'emission') {
                throw $failure;
            }
        },
        report: static function (Throwable $exception) use (
            &$reported,
        ): void {
            $reported[] = $exception;
        },
    );

    $runner->run(
        $application,
        static function (Application $running) use (
            &$events,
            $scenario,
            $failure,
        ): Response {
            httpRunnerCheck(
                $running->isBooted(),
                'Handling requires a booted application.',
            );

            $events[] = 'handle';

            if ($scenario === 'handling') {
                throw $failure;
            }

            return new Response(body: 'Ready');
        },
    );

    httpRunnerCheck(
        $events === ['handle', 'emit', 'cleanup'],
        $scenario . ': unexpected execution order.',
    );

    httpRunnerCheck(
        count($responses) === 1,
        $scenario . ': emission must be attempted exactly once.',
    );

    $response = $responses[0];

    httpRunnerCheck(
        $response->status() === ($scenario === 'handling' ? 500 : 200),
        $scenario . ': unexpected response status.',
    );

    httpRunnerCheck(
        $response->body() === (
            $scenario === 'handling' ? 'Internal server error' : 'Ready'
        ),
        $scenario . ': unexpected response body.',
    );

    httpRunnerCheck(
        $response->headers()->get('X-Content-Type-Options') === ['nosniff'],
        $scenario . ': response policy was not applied.',
    );

    httpRunnerCheck(
        $reported === ($scenario === 'success' ? [] : [$failure]),
        $scenario . ': failure reporting lost exception identity.',
    );

    httpRunnerCheck(
        $application->isTerminated(),
        $scenario . ': application was not terminated.',
    );
}

echo 'PASS: HTTP execution, emission, cleanup ordering, and failure reporting (4 cases).'
    . PHP_EOL;

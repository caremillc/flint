<?php

declare(strict_types=1);

use App\Http\HttpApplicationRunner;
use Flint\Foundation\Application;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function reportingFailureCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$application = new Application(dirname(__DIR__));
$cleaned = false;
$responses = [];
$reportAttempts = 0;

$application->onTerminate(
    static function () use (&$cleaned): void {
        $cleaned = true;
    },
);

$runner = new HttpApplicationRunner(
    emit: static function (Response $response) use (&$responses): void {
        $responses[] = $response;
    },
    report: static function (Throwable $exception) use (
        &$reportAttempts,
    ): void {
        ++$reportAttempts;

        throw new RuntimeException('Controlled diagnostic destination failure.');
    },
);

$runner->run(
    $application,
    static function (Application $running): Response {
        throw new RuntimeException('Controlled handling failure.');
    },
);

reportingFailureCheck(
    $reportAttempts === 1,
    'The failed reporter must not be retried recursively.',
);

reportingFailureCheck(
    count($responses) === 1,
    'Reporting failure must not prevent the error response.',
);

reportingFailureCheck(
    $responses[0]->status() === 500
        && $responses[0]->body() === 'Internal server error'
        && $responses[0]->headers()->get('X-Content-Type-Options')
            === ['nosniff'],
    'Reporting failure must preserve the generic response and security header.',
);

reportingFailureCheck(
    $cleaned && $application->isTerminated(),
    'Reporting failure must not prevent application cleanup.',
);

echo 'PASS: Reporting destination failure preserves response and cleanup.'
    . PHP_EOL;

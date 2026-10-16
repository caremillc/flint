<?php

declare(strict_types=1);

use App\Http\HttpApplicationRunner;
use App\Logging\ExceptionReporter;
use Flint\Foundation\Application;
use Flint\Foundation\ApplicationRunException;
use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function reportingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$records = [];

$reporter = new ExceptionReporter(
    static function (string $record) use (&$records): void {
        $records[] = $record;
    },
);

$id = str_repeat('a', 32);

$request = new Request(
    method: 'POST',
    target: '/example?token=query-secret',
    headers: new Headers()->with('Authorization', 'Bearer header-secret'),
    body: 'body-secret',
    attributes: ['request.id' => $id],
);

$previous = new RuntimeException('previous-secret');

$exception = new RuntimeException(
    "password=message-secret\r\nInvalid byte: \xFF",
    previous: $previous,
);

$reporter($exception, $request);

reportingCheck(count($records) === 1, 'Expected one handler record.');

$decoded = json_decode(
    $records[0],
    true,
    512,
    JSON_THROW_ON_ERROR,
);

reportingCheck(
    $decoded === [
        'event' => 'http.handler_failed',
        'request_id' => $id,
        'method' => 'POST',
        'exception' => RuntimeException::class,
        'message' => 'HTTP request handling failed.',
    ],
    'Handler records must contain only the approved fields.',
);

// Entry-point failures use the same restricted diagnostic policy.
$reporter->reportUncaught($exception);

$decoded = json_decode(
    $records[1],
    true,
    512,
    JSON_THROW_ON_ERROR,
);

reportingCheck(
    $decoded === [
        'event' => 'http.entrypoint_failed',
        'request_id' => null,
        'method' => null,
        'exception' => RuntimeException::class,
        'message' => 'HTTP entry point failed.',
    ],
    'Entry-point records must use safe diagnostics and absent request context.',
);

// Invalid correlation IDs must not enter the log.
foreach (['invalid', 123, null] as $invalidId) {
    $reporter(
        $exception,
        new Request(attributes: ['request.id' => $invalidId]),
    );

    $latest = json_decode(
        $records[array_key_last($records)],
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    reportingCheck(
        $latest['request_id'] === null,
        'Invalid request IDs must become null.',
    );
}

// Anonymous exception types must not expose their generated source location.
$reporter->reportUncaught(
    new class ('anonymous-secret') extends RuntimeException {},
);

$anonymousRecord = $records[array_key_last($records)];

$decoded = json_decode(
    $anonymousRecord,
    true,
    512,
    JSON_THROW_ON_ERROR,
);

reportingCheck(
    $decoded['exception'] === 'anonymous Throwable',
    'Anonymous exception types must use a location-free label.',
);

reportingCheck(
    !str_contains($anonymousRecord, 'exception-reporting.php'),
    'Anonymous exception diagnostics must not include source filenames.',
);

// Exercise the real runner-to-reporter integration for combined failure.
$application = new Application(dirname(__DIR__));
$emissionFailure = new RuntimeException('emission-secret');
$cleanupFailure = new LogicException('cleanup-secret');
$attempts = 0;

$application->onTerminate(
    static function () use ($cleanupFailure): void {
        throw $cleanupFailure;
    },
);

$before = count($records);

$runner = new HttpApplicationRunner(
    emit: static function (Response $response) use (
        &$attempts,
        $emissionFailure,
    ): void {
        ++$attempts;

        throw $emissionFailure;
    },
    report: $reporter->reportUncaught(...),
);

$runner->run(
    $application,
    static fn (Application $running): Response => new Response(body: 'Ready'),
);

reportingCheck(
    $attempts === 1 && $application->isTerminated(),
    'Combined failure must terminate without another emission attempt.',
);

reportingCheck(
    count($records) === $before + 1,
    'Combined failure must produce one diagnostic record.',
);

$decoded = json_decode(
    $records[array_key_last($records)],
    true,
    512,
    JSON_THROW_ON_ERROR,
);

reportingCheck(
    $decoded === [
        'event' => 'http.entrypoint_failed',
        'request_id' => null,
        'method' => null,
        'exception' => ApplicationRunException::class,
        'message' => 'HTTP entry point failed.',
        'execution_exception' => RuntimeException::class,
        'termination_exception' => LogicException::class,
    ],
    'Combined diagnostics must identify both failure types safely.',
);

// Every emitted record must remain single-line JSON without secret values.
foreach ($records as $record) {
    reportingCheck(
        !str_contains($record, "\r") && !str_contains($record, "\n"),
        'Log records must not contain literal line breaks.',
    );

    foreach ([
        'query-secret',
        'header-secret',
        'body-secret',
        'message-secret',
        'previous-secret',
        'anonymous-secret',
        'emission-secret',
        'cleanup-secret',
    ] as $secret) {
        reportingCheck(
            !str_contains($record, $secret),
            'Diagnostic record exposed a forbidden value.',
        );
    }
}

// The reporter itself preserves writer failure identity.
$writerFailure = new RuntimeException('Writer unavailable.');

$brokenReporter = new ExceptionReporter(
    static function (string $record) use ($writerFailure): void {
        throw $writerFailure;
    },
);

$caught = null;

try {
    $brokenReporter->reportUncaught($exception);
} catch (Throwable $failure) {
    $caught = $failure;
}

reportingCheck(
    $caught === $writerFailure,
    'Reporter writer failures must propagate unchanged.',
);

echo 'PASS: Restricted exception diagnostics, secret exclusion, correlation, '
    . 'anonymous types, combined failures, and writer failure identity.'
    . PHP_EOL;

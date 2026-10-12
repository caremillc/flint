<?php

declare(strict_types=1);

use App\Logging\ExceptionReporter;
use Flint\Http\Headers;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function reportingCheck(bool $condition, string $message): void
{
    if (! $condition) {
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

$message = "First line\r\nSecond line\twith a tab";
$exception = new RuntimeException($message);

$reporter($exception, $request);

reportingCheck(count($records) === 1, 'Expected one log record.');

$record = $records[0];

reportingCheck(
    ! str_contains($record, "\r") && ! str_contains($record, "\n"),
    'A log record must not contain literal line breaks.',
);

$decoded = json_decode(
    $record,
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

reportingCheck(
    $decoded === [
        'event' => 'http.handler_failed',
        'request_id' => $id,
        'method' => 'POST',
        'exception' => RuntimeException::class,
        'message' => $message,
    ],
    'The structured record must preserve the expected fields.',
);

foreach (['query-secret', 'header-secret', 'body-secret'] as $secret) {
    reportingCheck(
        ! str_contains($record, $secret),
        'The reporter must not copy request secrets into the record.',
    );
}

// Invalid UTF-8 in an exception message must not prevent reporting.
$reporter(new RuntimeException("Invalid byte: \xFF"), new Request());

$invalidUtf8 = json_decode(
    $records[1],
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

reportingCheck(
    $invalidUtf8['message'] === "Invalid byte: \u{FFFD}",
    'Invalid UTF-8 must be replaced.',
);
reportingCheck(
    $invalidUtf8['request_id'] === null,
    'Missing request IDs must be represented as null.',
);

// Invalid internal IDs must not become correlation identifiers.
foreach (['invalid', 123, null] as $invalidId) {
    $reporter(
        new RuntimeException('Example'),
        new Request(attributes: ['request.id' => $invalidId]),
    );

    $latest = json_decode(
        $records[array_key_last($records)],
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    reportingCheck(
        $latest['request_id'] === null,
        'Invalid request IDs must be represented as null.',
    );
}

// Writer failures retain their identity for the outer error boundary.
$writeFailure = new RuntimeException('Log destination unavailable.');

$brokenReporter = new ExceptionReporter(
    static function (string $record) use ($writeFailure): void {
        throw $writeFailure;
    },
);

$caught = null;

try {
    $brokenReporter(new RuntimeException('Handler failed.'), new Request());
} catch (Throwable $exception) {
    $caught = $exception;
}

reportingCheck(
    $caught === $writeFailure,
    'Writer failures must propagate unchanged.',
);

$entrypointRecords = [];

$entrypointReporter = new ExceptionReporter(
    static function (string $record) use (&$entrypointRecords): void {
        $entrypointRecords[] = $record;
    },
);

$entrypointFailure = new RuntimeException("Bootstrap failed.\nDetails.");

$entrypointReporter->reportUncaught($entrypointFailure);

reportingCheck(
    count($entrypointRecords) === 1,
    'An entry-point failure must produce one record.',
);

$entrypointRecord = $entrypointRecords[0];

reportingCheck(
    ! str_contains($entrypointRecord, "\n")
        && ! str_contains($entrypointRecord, "\r"),
    'Entry-point records must not contain literal line breaks.',
);

$entrypointDecoded = json_decode(
    $entrypointRecord,
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

reportingCheck(
    $entrypointDecoded === [
        'event' => 'http.entrypoint_failed',
        'request_id' => null,
        'method' => null,
        'exception' => RuntimeException::class,
        'message' => "Bootstrap failed.\nDetails.",
    ],
    'Entry-point records must identify unavailable request context.',
);

echo 'PASS: Structured exception reporting, escaping, and failure propagation.'
    . PHP_EOL;

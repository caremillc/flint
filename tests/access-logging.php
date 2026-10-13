<?php

declare(strict_types=1);

use App\Http\Middleware\AccessLogMiddleware;
use App\Http\Middleware\ExceptionMiddleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function accessLogCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$records = [];
$tick = 0;

$access = new AccessLogMiddleware(
    static function (string $record) use (&$records): void {
        $records[] = $record;
    },
    static function () use (&$tick): float {
        return ++$tick / 1000;
    },
);

$response = new Response(body: 'Ready');

$destination = new class ($response) implements RequestHandler {
    public function __construct(
        private readonly Response $response,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->response;
    }
};

$id = str_repeat('b', 32);

$request = new Request(
    method: 'POST',
    target: '/private?token=secret',
    body: 'private-body',
    attributes: ['request.id' => $id],
);

$received = $access->process($request, $destination);

accessLogCheck(
    $received === $response,
    'Access logging must preserve response identity.',
);
accessLogCheck(
    count($records) === 1,
    'A completed request must produce one access record.',
);

$record = json_decode(
    $records[0],
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

accessLogCheck(
    array_keys($record) === [
        'event',
        'request_id',
        'method',
        'status',
        'duration_ms',
    ],
    'Access records must contain only the intended fields.',
);
accessLogCheck(
    $record['event'] === 'http.request_completed'
        && $record['request_id'] === $id
        && $record['method'] === 'POST'
        && $record['status'] === 200,
    'Access record metadata is incorrect.',
);
accessLogCheck(
    (float) $record['duration_ms'] === 1.0,
    'Duration must be recorded in milliseconds.',
);

// Verify completion logging around a handled exception.
$failure = new RuntimeException('Expected handler failure.');
$reported = [];

$throwing = new class ($failure) implements RequestHandler {
    public function __construct(
        private readonly RuntimeException $failure,
    ) {}

    public function handle(Request $request): Response
    {
        throw $this->failure;
    }
};

$pipeline = new MiddlewarePipeline(
    $throwing,
    $access,
    new ExceptionMiddleware(
        static function (
            Throwable $exception,
            Request $request,
        ) use (&$reported): void {
            $reported[] = $exception;
        },
    ),
);

$failed = $pipeline->handle($request);

accessLogCheck($failed->status() === 500, 'Expected a handled 500 response.');
accessLogCheck(
    count($reported) === 1 && $reported[0] === $failure,
    'The original exception must be reported.',
);
accessLogCheck(
    count($records) === 2,
    'The handled failure must produce an access record.',
);

$failedRecord = json_decode(
    $records[1],
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

accessLogCheck(
    $failedRecord['status'] === 500
        && $failedRecord['request_id'] === $id,
    'The failure completion record must retain status and request ID.',
);

// An exception escaping downstream is not recorded as completed.
$countBefore = count($records);
$caught = null;

try {
    $access->process($request, $throwing);
} catch (Throwable $exception) {
    $caught = $exception;
}

accessLogCheck(
    $caught === $failure,
    'Escaping exceptions must propagate unchanged.',
);
accessLogCheck(
    count($records) === $countBefore,
    'An escaping exception must not produce a completion record.',
);

echo 'PASS: Access logging, duration, response identity, and handled failures.'
    . PHP_EOL;

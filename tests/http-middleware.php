<?php

declare(strict_types=1);

use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function middlewareCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param Closure(Request): Response $callback
 */
function middlewareHandler(Closure $callback): RequestHandler
{
    return new class ($callback) implements RequestHandler {
        /**
         * @param Closure(Request): Response $callback
         */
        public function __construct(
            private readonly Closure $callback,
        ) {}

        public function handle(Request $request): Response
        {
            return ($this->callback)($request);
        }
    };
}

$request = new Request();
$reported = [];

$exceptions = new ExceptionMiddleware(
    static function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception;
    },
);

// Successful responses pass through unchanged.
$original = new Response(body: 'Ready');

$success = $exceptions->process(
    $request,
    middlewareHandler(
        static fn (Request $request): Response => $original,
    ),
);

middlewareCheck(
    $success === $original,
    'Successful responses must retain identity.',
);
middlewareCheck(
    $reported === [],
    'Successful requests must not report an exception.',
);

// Both ordinary exceptions and PHP errors become generic responses.
$failures = [
    new RuntimeException('Private exception details.'),
    new Error('Private error details.'),
];

foreach ($failures as $failure) {
    $reported = [];

    $response = $exceptions->process(
        $request,
        middlewareHandler(
            static function (Request $request) use ($failure): Response {
                throw $failure;
            },
        ),
    );

    middlewareCheck($response->status() === 500, 'Expected status 500.');
    middlewareCheck(
        $response->body() === 'Internal server error',
        'The response must not expose exception details.',
    );
    middlewareCheck(
        $response->headers()->get('Content-Type')
            === ['text/plain; charset=utf-8'],
        'The error response must use the text content type.',
    );
    middlewareCheck(
        count($reported) === 1 && $reported[0] === $failure,
        'Report the original exception exactly once.',
    );
}

// The configured order decorates errors and remains usable afterward.
$reported = [];
$calls = 0;
$routeFailure = new RuntimeException('First request failed.');

$destination = middlewareHandler(
    static function (Request $request) use (&$calls, $routeFailure): Response {
        ++$calls;

        if ($calls === 1) {
            throw $routeFailure;
        }

        return new Response(body: 'Recovered');
    },
);

$pipeline = new MiddlewarePipeline(
    $destination,
    new ResponseHeadersMiddleware(),
    $exceptions,
);

$failed = $pipeline->handle($request);
$recovered = $pipeline->handle($request);

middlewareCheck($failed->status() === 500, 'First request must return 500.');
middlewareCheck(
    $failed->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'Error responses must receive the middleware header.',
);
middlewareCheck(
    $recovered->status() === 200 && $recovered->body() === 'Recovered',
    'The pipeline must remain usable after a handler exception.',
);
middlewareCheck(
    $recovered->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'Recovered responses must receive the middleware header.',
);
middlewareCheck(
    count($reported) === 1 && $reported[0] === $routeFailure,
    'Recovery must not generate an additional exception report.',
);

// Reporting failures propagate to the outer entry-point boundary.
$reportFailure = new RuntimeException('Reporter failed.');

$brokenReporter = new ExceptionMiddleware(
    static function (Throwable $exception) use ($reportFailure): void {
        throw $reportFailure;
    },
);

$caught = null;

try {
    $brokenReporter->process(
        $request,
        middlewareHandler(
            static function (Request $request): Response {
                throw new RuntimeException('Route failed.');
            },
        ),
    );
} catch (Throwable $exception) {
    $caught = $exception;
}

middlewareCheck(
    $caught === $reportFailure,
    'Reporting failures must propagate unchanged.',
);

echo 'PASS: Exception middleware, reporting, response headers, and recovery.'
    . PHP_EOL;

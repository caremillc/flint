<?php

declare(strict_types=1);

use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use Flint\Http\Headers;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function requestIdCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$destination = new class implements RequestHandler {
    /** @var list<Request> */
    public array $requests = [];

    public function handle(Request $request): Response
    {
        $this->requests[] = $request;

        if (count($this->requests) === 1) {
            throw new RuntimeException('Expected test failure.');
        }

        return new Response(
            body: 'Recovered',
            headers: new Headers()->with('X-Request-ID', 'handler-value'),
        );
    }
};

$sequence = 0;
$reports = [];

$pipeline = new MiddlewarePipeline(
    $destination,
    new RequestIdMiddleware(
        static function () use (&$sequence): string {
            return sprintf('%032x', ++$sequence);
        },
    ),
    new ResponseHeadersMiddleware(),
    new ExceptionMiddleware(
        static function (
            Throwable $exception,
            Request $request,
        ) use (&$reports): void {
            $reports[] = [$exception, $request];
        },
    ),
);

$original = new Request(
    headers: new Headers()->with('X-Request-ID', 'client-value'),
    attributes: ['request.id' => 'old-value'],
);

$failed = $pipeline->handle($original);
$recovered = $pipeline->handle($original);

$firstId = sprintf('%032x', 1);
$secondId = sprintf('%032x', 2);

requestIdCheck($failed->status() === 500, 'Expected a handled error.');
requestIdCheck(
    $failed->headers()->get('X-Request-ID') === [$firstId],
    'Handled errors must carry the generated request ID.',
);
requestIdCheck(
    $failed->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'Handled errors must retain the response middleware header.',
);

requestIdCheck(
    count($reports) === 1,
    'Exactly one exception report was expected.',
);
requestIdCheck(
    $reports[0][1] === $destination->requests[0],
    'The reporter and handler must receive the same request.',
);
requestIdCheck(
    $reports[0][1]->attribute('request.id') === $firstId,
    'The reported ID must match the response ID.',
);

requestIdCheck(
    $recovered->status() === 200 && $recovered->body() === 'Recovered',
    'The next request must succeed.',
);
requestIdCheck(
    $recovered->headers()->get('X-Request-ID') === [$secondId],
    'Each request must receive a new ID that replaces handler headers.',
);
requestIdCheck(
    $destination->requests[1]->attribute('request.id') === $secondId,
    'The second handler invocation must receive the second ID.',
);

requestIdCheck(
    $original->attribute('request.id') === 'old-value',
    'The original request attributes must remain unchanged.',
);
requestIdCheck(
    $original->headers()->get('X-Request-ID') === ['client-value'],
    'The original request headers must remain unchanged.',
);

// Exercise the production generator as well.
$productionPipeline = new MiddlewarePipeline(
    $destination,
    new RequestIdMiddleware(),
);

$productionResponse = $productionPipeline->handle(new Request());
$generatedIds = $productionResponse->headers()->get('X-Request-ID');

requestIdCheck(
    count($generatedIds) === 1
        && preg_match('/\A[0-9a-f]{32}\z/', $generatedIds[0]) === 1,
    'Production IDs must contain 32 lowercase hexadecimal characters.',
);

echo 'PASS: Request IDs, error correlation, replacement, and recovery.'
    . PHP_EOL;

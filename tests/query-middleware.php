<?php

declare(strict_types=1);

use App\Http\Middleware\QueryParametersMiddleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function queryMiddlewareCheck(bool $condition, string $message): void
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

        return new Response(body: 'Reached handler');
    }
};

$middleware = new QueryParametersMiddleware();

$original = new Request(
    target: '/example?name=Ada+Lovelace&enabled=1',
    attributes: [
        'request.id' => 'retained',
        'request.query' => ['stale' => 'value'],
    ],
);

$response = $middleware->process($original, $destination);

queryMiddlewareCheck(
    $response->status() === 200,
    'A valid query must reach the handler.',
);
queryMiddlewareCheck(
    count($destination->requests) === 1,
    'The handler must be called once.',
);

$received = $destination->requests[0];

queryMiddlewareCheck(
    $received->attribute('request.query') === [
        'name' => 'Ada Lovelace',
        'enabled' => '1',
    ],
    'Decoded query values must replace any stale query attribute.',
);
queryMiddlewareCheck(
    $received->attribute('request.id') === 'retained',
    'Other attributes must be preserved.',
);
queryMiddlewareCheck(
    $original->attribute('request.query') === ['stale' => 'value'],
    'The original request must remain unchanged.',
);
queryMiddlewareCheck(
    $received->target() === $original->target(),
    'Query decoding must preserve the raw request target.',
);

$invalidTargets = [
    '/example?name=Ada&name=Grace',
    '/example?name=Ada&%6Eame=Grace',
    '/example?=value',
    '/example?a=1&',
    '/example?' . implode('&', array_fill(0, 101, 'flag')),
    '/example?value=' . str_repeat('x', 8192),
];

foreach ($invalidTargets as $target) {
    $before = count($destination->requests);

    $rejected = $middleware->process(
        new Request(target: $target),
        $destination,
    );

    queryMiddlewareCheck(
        $rejected->status() === 400
            && $rejected->body() === 'Bad request',
        'Invalid queries must receive a generic 400 response.',
    );
    queryMiddlewareCheck(
        count($destination->requests) === $before,
        'Rejected queries must not reach the handler.',
    );
}

// A valid request must still work after rejected requests.
$recovered = $middleware->process(
    new Request(target: '/example'),
    $destination,
);

queryMiddlewareCheck(
    $recovered->status() === 200,
    'The middleware must remain usable after rejection.',
);

$latest = $destination->requests[array_key_last($destination->requests)];

queryMiddlewareCheck(
    $latest->attribute('request.query') === [],
    'An absent query must produce an empty parameter map.',
);

// Downstream programming errors must not be classified as bad queries.
$failure = new InvalidArgumentException('Handler failure.');

$throwing = new class ($failure) implements RequestHandler {
    public function __construct(
        private readonly InvalidArgumentException $failure,
    ) {}

    public function handle(Request $request): Response
    {
        throw $this->failure;
    }
};

$caught = null;

try {
    $middleware->process(new Request(), $throwing);
} catch (Throwable $exception) {
    $caught = $exception;
}

queryMiddlewareCheck(
    $caught === $failure,
    'Downstream exceptions must propagate unchanged.',
);

echo 'PASS: Query middleware, rejection, attribute isolation, and recovery.'
    . PHP_EOL;

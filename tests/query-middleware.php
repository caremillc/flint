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
    'Decoded values must replace any stale query attribute.',
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
    'duplicate name' => '/example?name=Ada&name=Grace',
    'encoded duplicate' => '/example?name=Ada&%6Eame=Grace',
    'empty name' => '/example?=value',
    'trailing empty field' => '/example?a=1&',
    'parameter limit' => '/example?'
        . implode('&', array_fill(0, 101, 'flag')),
    'byte limit' => '/example?value=' . str_repeat('x', 8192),
    'numeric zero name' => '/example?0=value',
    'numeric positive name' => '/example?42=value',
    'numeric negative name' => '/example?-1=value',
    'encoded numeric name' => '/example?%30=value',
    'encoded repeat duplicate' => '/example?repeat=2&%72epeat=3',
];

foreach ($invalidTargets as $label => $target) {
    $before = count($destination->requests);

    $request = new Request(
        target: $target,
        attributes: [
            'request.id' => 'retained',
            'request.query' => ['original' => 'unchanged'],
        ],
    );

    $rejected = $middleware->process($request, $destination);

    queryMiddlewareCheck(
        $rejected->status() === 400
            && $rejected->body() === 'Bad request',
        $label . ': expected a generic 400 response.',
    );

    queryMiddlewareCheck(
        $rejected->headers()->get('Content-Type')
            === ['text/plain; charset=utf-8'],
        $label . ': unexpected rejection content type.',
    );

    queryMiddlewareCheck(
        count($destination->requests) === $before,
        $label . ': rejected query reached the handler.',
    );

    queryMiddlewareCheck(
        $request->attribute('request.query')
            === ['original' => 'unchanged'],
        $label . ': rejection mutated the original request.',
    );
}

// Brackets are literal characters, not PHP-style array syntax.
$literalRequest = new Request(
    target: '/example?repeat%5B%5D=2&repeat=3',
);

$literalResponse = $middleware->process(
    $literalRequest,
    $destination,
);

queryMiddlewareCheck(
    $literalResponse->status() === 200,
    'Distinct literal bracket names must remain valid.',
);

$latest = $destination->requests[array_key_last($destination->requests)];

queryMiddlewareCheck(
    $latest->attribute('request.query') === [
        'repeat[]' => '2',
        'repeat' => '3',
    ],
    'Bracket names must remain literal and distinct.',
);

queryMiddlewareCheck(
    ! $literalRequest->hasAttribute('request.query'),
    'Successful decoding must not mutate the original request.',
);

// Verify recovery with another request on the same middleware instance.
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

// Downstream programming errors must propagate unchanged.
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
    'Downstream exceptions must not become query rejection responses.',
);

echo 'PASS: Query middleware, numeric-name rejection, literal brackets, '
    . 'duplicate detection, isolation, and recovery.'
    . PHP_EOL;

<?php

declare(strict_types=1);

use App\Http\Middleware\AccessLogMiddleware;
use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\QueryParametersMiddleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteConstraint;
use Flint\Routing\RouteLogContext;
use Flint\Routing\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routeAccessCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$router = new Router();
$seenNames = [];

$handler = static function (Request $request) use (
    &$seenNames,
): Response {
    $seenNames[] = $request->attribute('route.name');

    return ResponseFactory::text('ok');
};

$router->add('GET', '/health', $handler, 'health');

$router->add(
    'GET',
    '/items/{id}',
    $handler,
    'items.show',
    ['id' => RouteConstraint::Digits],
);

$router->add('GET', '/explicit', $handler, 'explicit.get');
$router->add('HEAD', '/explicit', $handler, 'explicit.head');
$router->add('GET', '/unnamed', $handler);

$failure = new RuntimeException('Expected route failure.');

$router->add(
    'GET',
    '/failure',
    static function (Request $request) use ($failure): Response {
        throw $failure;
    },
    'failure',
);

$router->freeze();

$records = [];
$reported = [];

$pipeline = new MiddlewarePipeline(
    $router,
    new AccessLogMiddleware(
        static function (string $record) use (&$records): void {
            $records[] = $record;
        },
    ),
    new ExceptionMiddleware(
        static function (
            Throwable $exception,
            Request $request,
        ) use (&$reported): void {
            $reported[] = $exception;
        },
    ),
    new QueryParametersMiddleware(),
);

// Incoming routing metadata must never be reused by access logging.
$incomingContext = new RouteLogContext();
$incomingContext->select('forged.route');

$cases = [
    ['GET', '/health', 200, 'health'],
    ['HEAD', '/health', 200, 'health'],
    ['GET', '/items/00042', 200, 'items.show'],
    ['HEAD', '/items/00042', 200, 'items.show'],
    ['HEAD', '/explicit', 200, 'explicit.head'],
    ['GET', '/items/invalid', 404, null],
    ['POST', '/health', 405, null],
    ['GET', '/missing', 404, null],
    ['GET', '/unnamed', 200, null],
    ['GET', '/health?name=Ada&name=Grace', 400, null],
    ['GET', '/failure', 500, 'failure'],
    ['GET', '/health', 200, 'health'],
];

foreach ($cases as [$method, $target, $status, $routeName]) {
    $request = new Request(
        method: $method,
        target: $target,
        attributes: [
            'request.id' => str_repeat('a', 32),
            'route.name' => 'forged.route',
            'route.log_context' => $incomingContext,
        ],
    );

    $before = count($records);
    $response = $pipeline->handle($request);

    routeAccessCheck(
        $response->status() === $status,
        $method . ' ' . $target . ': unexpected response status.',
    );

    routeAccessCheck(
        count($records) === $before + 1,
        'Each completed request must produce one access record.',
    );

    $record = json_decode(
        $records[$before],
        true,
        64,
        JSON_THROW_ON_ERROR,
    );

    routeAccessCheck(
        is_array($record)
            && array_key_exists('route_name', $record)
            && $record['route_name'] === $routeName
            && $record['method'] === $method
            && $record['status'] === $status,
        $method . ' ' . $target . ': incorrect routing metadata.',
    );

    routeAccessCheck(
        ! array_key_exists('path', $record)
            && ! array_key_exists('target', $record)
            && ! array_key_exists('query', $record)
            && ! array_key_exists('body', $record),
        'Access records must not include request content.',
    );

    routeAccessCheck(
        $request->attribute('route.name') === 'forged.route'
            && $request->attribute('route.log_context') === $incomingContext
            && $incomingContext->routeName() === 'forged.route',
        'The original request and incoming context must remain unchanged.',
    );
}

routeAccessCheck(
    $seenNames === [
        'health',
        'health',
        'items.show',
        'items.show',
        'explicit.head',
        null,
        'health',
    ],
    'Selected handlers must receive authoritative route names.',
);

routeAccessCheck(
    count($reported) === 1 && $reported[0] === $failure,
    'The handled route failure must report its original exception.',
);

echo 'PASS: Route names in access logs, HEAD selection, rejection boundaries, failure correlation, and isolation.'
    . PHP_EOL;

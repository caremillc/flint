<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use App\Http\Middleware\QueryParametersMiddleware;
use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Routing\Router;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routeConstraintsCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Expected a route registration closure.');
}

$container = new Container();
$constructed = 0;

$container->bind(
    GreetingHandler::class,
    static function (ContainerInterface $services) use (
        &$constructed,
    ): GreetingHandler {
        ++$constructed;

        return new GreetingHandler();
    },
);

$router = new Router();

$registerRoutes(
    $router,
    Configuration::fromLayers([
        'app.name' => 'Constrained routes',
        'http.json.max_body_bytes' => 4096,
    ]),
    $container,
);

$pipeline = new MiddlewarePipeline(
    $router,
    new QueryParametersMiddleware(),
);

$cases = [
    ['GET', '/api/v1/hello/Ada_Lovelace', 404, 'Not found'],
    ['GET', '/api/v1/hello/a%2Fb', 404, 'Not found'],
    ['POST', '/api/v1/hello/Ada', 405, 'Method not allowed'],
    [
        'GET',
        '/api/v1/hello/Ada-Lovelace',
        200,
        '{"greeting":"Hello, Ada-Lovelace"}',
    ],
    [
        'HEAD',
        '/api/v1/hello/Ada-Lovelace',
        200,
        '{"greeting":"Hello, Ada-Lovelace"}',
    ],
    [
        'GET',
        '/api/v1/hello/Ada?style=formal',
        200,
        '{"greeting":"Welcome, Ada"}',
    ],
    [
        'GET',
        '/hello/a%2Fb',
        200,
        '{"greeting":"Hello, a%2Fb"}',
    ],
];

foreach ($cases as [$method, $target, $status, $body]) {
    $response = $pipeline->handle(new Request($method, $target));
    $label = $method . ' ' . $target;

    routeConstraintsCheck(
        $response->status() === $status,
        $label . ': unexpected status.',
    );

    // This checks the response before emission, including HEAD.
    routeConstraintsCheck(
        $response->body() === $body,
        $label . ': unexpected response representation.',
    );

    if ($status === 200 && str_starts_with($target, '/api/')) {
        routeConstraintsCheck(
            $response->headers()->get('Cache-Control') === ['no-store'],
            $label . ': inherited middleware was not applied.',
        );
    }
}

routeConstraintsCheck(
    $constructed === 4,
    'Only the four matched greeting requests should construct handlers.',
);

routeConstraintsCheck(
    $router->path(
        'api.v1.greeting.show',
        ['name' => 'Ada-Lovelace'],
    ) === '/api/v1/hello/Ada-Lovelace',
    'Named route generation must preserve valid slug parameters.',
);

$rejected = false;

try {
    $router->path(
        'api.v1.greeting.show',
        ['name' => 'a/b'],
    );
} catch (InvalidArgumentException) {
    $rejected = true;
}

routeConstraintsCheck(
    $rejected,
    'Named route generation must reject invalid slug parameters.',
);

echo 'PASS: Constrained API routes, lazy resolution, named URLs, and legacy route compatibility.'
    . PHP_EOL;

<?php

declare(strict_types=1);

use App\Http\Handlers\HomeHandler;
use App\Http\Middleware\QueryParametersMiddleware;
use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Http\Headers;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Routing\Router;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routeHandlersCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Expected a route registration closure.');
}

$configuration = Configuration::fromLayers([
    'app.name' => 'First application',
    'http.json.max_body_bytes' => 4096,
]);

$container = new Container();
$homeConstructions = 0;

// Verify that an explicit application binding is preserved.
$container->bind(
    HomeHandler::class,
    static function (ContainerInterface $services) use (
        &$homeConstructions,
    ): HomeHandler {
        ++$homeConstructions;

        $configuration = $services->get(Configuration::class);

        if (!$configuration instanceof Configuration) {
            throw new RuntimeException('Missing configuration dependency.');
        }

        return new HomeHandler($configuration);
    },
);

$router = new Router();
$registerRoutes($router, $configuration, $container);

routeHandlersCheck(
    $homeConstructions === 0,
    'Route registration must not construct the home handler.',
);

$handler = new MiddlewarePipeline(
    $router,
    new QueryParametersMiddleware(),
);

$cases = [
    ['GET', '/', '', 'application/json', 200, false],
    ['GET', '/health', '', 'application/json', 200, false],
    ['GET', '/hello/Ada', '', 'application/json', 200, false],
    ['POST', '/hello', '{"name":"Ada"}', 'application/json', 200, true],
    ['POST', '/hello', '{"name":', 'application/json', 400, true],
    ['POST', '/hello', '{"name":42}', 'application/json', 422, true],
    ['POST', '/hello', '{"name":"Ada"}', 'text/plain', 415, true],
    ['GET', '/missing', '', 'application/json', 404, false],
    ['POST', '/health', '', 'application/json', 405, false],
    ['HEAD', '/hello', '', 'application/json', 405, false],
    [
        'POST',
        '/hello?name=Ada&name=Grace',
        '{"name":"Ada"}',
        'application/json',
        400,
        false,
    ],
];

foreach ($cases as [
    $method,
    $target,
    $body,
    $contentType,
    $status,
    $noStore,
]) {
    $response = $handler->handle(new Request(
        $method,
        $target,
        (new Headers())->with('Content-Type', $contentType),
        $body,
    ));

    $label = $method . ' ' . $target;

    routeHandlersCheck(
        $response->status() === $status,
        $label . ': unexpected status.',
    );

    routeHandlersCheck(
        $response->headers()->get('Cache-Control')
            === ($noStore ? ['no-store'] : []),
        $label . ': incorrect route middleware scope.',
    );
}

routeHandlersCheck(
    $homeConstructions === 1,
    'The explicit home binding must run only for the selected home route.',
);

routeHandlersCheck(
    $handler->handle(new Request('GET', '/'))->body()
        === 'First application',
    'The home handler must receive registered application configuration.',
);

routeHandlersCheck(
    $homeConstructions === 2,
    'Transient home bindings must remain transient.',
);

routeHandlersCheck(
    $router->path('greeting', ['name' => 'Ada']) === '/hello/Ada'
        && $router->path('greeting.create') === '/hello',
    'Named routes must retain their paths.',
);

// The original two-argument registration contract remains supported.
$secondRouter = new Router();

$registerRoutes(
    $secondRouter,
    Configuration::fromLayers([
        'app.name' => 'Second application',
        'http.json.max_body_bytes' => 4096,
    ]),
);

routeHandlersCheck(
    $secondRouter->handle(new Request('GET', '/'))->body()
        === 'Second application',
    'Independent registration must use its own configuration.',
);

routeHandlersCheck(
    $handler->handle(new Request('GET', '/'))->body()
        === 'First application',
    'A second registration must not replace the first configuration.',
);

echo 'PASS: Container route handlers, constructor injection, middleware scope, and application isolation.'
    . PHP_EOL;

<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Routing\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routeGroupsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Expected a route registration closure.');
}

$router = new Router();

$registerRoutes(
    $router,
    Configuration::fromLayers([
        'app.name' => 'Route group application',
        'http.json.max_body_bytes' => 4096,
    ]),
);

routeGroupsCheck(
    $router->path('health') === '/health'
        && $router->path('greeting.create') === '/hello',
    'Existing named routes must remain unchanged.',
);

routeGroupsCheck(
    $router->path('api.v1.health') === '/api/v1/health'
        && $router->path('api.v1.greeting.create') === '/api/v1/hello',
    'Nested route names must generate their complete paths.',
);

routeGroupsCheck(
    $router->target(
        'api.v1.greeting.create',
        query: ['source' => 'a b'],
    ) === '/api/v1/hello?source=a%20b',
    'Grouped routes must support query-string generation.',
);

$cases = [
    ['GET', '/api/v1/health', '', 200, true, []],
    ['HEAD', '/api/v1/health', '', 200, true, []],
    [
        'POST',
        '/api/v1/hello',
        '{"name":"Ada"}',
        200,
        true,
        [],
    ],
    [
        'POST',
        '/api/v1/hello',
        '{"name":42}',
        422,
        true,
        [],
    ],
    ['GET', '/api/v1/missing', '', 404, false, []],
    ['POST', '/api/v1/health', '', 405, false, ['GET, HEAD']],
    ['HEAD', '/api/v1/hello', '', 405, false, ['POST']],
];

foreach ($cases as [
    $method,
    $target,
    $body,
    $status,
    $noStore,
    $allow,
]) {
    $response = $router->handle(new Request(
        $method,
        $target,
        (new Headers())->with('Content-Type', 'application/json'),
        $body,
    ));

    $label = $method . ' ' . $target;

    routeGroupsCheck(
        $response->status() === $status,
        $label . ': unexpected status.',
    );

    routeGroupsCheck(
        $response->headers()->get('Cache-Control')
            === ($noStore ? ['no-store'] : []),
        $label . ': incorrect group middleware scope.',
    );

    routeGroupsCheck(
        $response->headers()->get('Allow') === $allow,
        $label . ': unexpected Allow header.',
    );
}

routeGroupsCheck(
    $router->handle(new Request('GET', '/health'))
        ->headers()->get('Cache-Control') === [],
    'API group middleware must not affect the original health route.',
);

echo 'PASS: Versioned routes, nested names, inherited middleware, and method boundaries.'
    . PHP_EOL;

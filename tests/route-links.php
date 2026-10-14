<?php

declare(strict_types=1);

use Flint\Http\Request;
use Flint\Routing\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routeLinksCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function loadLinksRouter(): Router
{
    ob_start();

    try {
        $router = require dirname(__DIR__) . '/bootstrap/routes.php';
    } finally {
        $output = ob_get_clean();
    }

    routeLinksCheck(
        $output === '',
        'Route bootstrap must remain silent.',
    );

    if (! $router instanceof Router) {
        throw new RuntimeException(
            'Route bootstrap must return a Router.',
        );
    }

    return $router;
}

$router = loadLinksRouter();
$before = $router->describe();

$response = $router->handle(
    new Request(target: '/links'),
);

routeLinksCheck(
    $response->status() === 200,
    'GET /links must return 200.',
);

routeLinksCheck(
    $response->headers()->get('Content-Type')
        === ['application/json'],
    'GET /links must return JSON.',
);

$document = json_decode(
    $response->body(),
    true,
    64,
    JSON_THROW_ON_ERROR,
);

$expected = [
    'links' => [
        'self' => '/links',
        'health' => '/health',
        'greeting' => '/hello/Ada%20Lovelace?style=formal',
        'api_health' => '/api/v1/health',
        'api_greeting'
            => '/api/v1/hello/Ada-Lovelace?style=formal',
    ],
];

routeLinksCheck(
    $document === $expected,
    'The links document must contain the expected generated targets.',
);

routeLinksCheck(
    $router->path('links') === '/links',
    'The links endpoint must have a registered route name.',
);

foreach (['self', 'health', 'api_health'] as $name) {
    $linkedResponse = $router->handle(
        new Request(target: $expected['links'][$name]),
    );

    routeLinksCheck(
        $linkedResponse->status() === 200,
        'Generated link "' . $name . '" must resolve.',
    );
}

$methodResponse = $router->handle(
    new Request(method: 'POST', target: '/links'),
);

routeLinksCheck(
    $methodResponse->status() === 405
        && $methodResponse->headers()->get('Allow')
            === ['GET, HEAD'],
    'POST /links must preserve method rejection.',
);

routeLinksCheck(
    $router->isFrozen() && $router->describe() === $before,
    'Resolving handlers and generating links must not change routes.',
);

$independent = loadLinksRouter();

$independentResponse = $independent->handle(
    new Request(target: '/links'),
);

routeLinksCheck(
    $independent !== $router
        && $independentResponse->status() === 200
        && $independentResponse->body() === $response->body(),
    'Independent applications must resolve their URL generator correctly.',
);

echo 'PASS: Injected route URLs, linked endpoints, method rejection, and independent bootstrap.'
    . PHP_EOL;

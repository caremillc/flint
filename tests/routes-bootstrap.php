<?php

declare(strict_types=1);

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function routesBootstrapCheck(
    bool $condition,
    string $message,
): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function loadApplicationRoutes(): Router
{
    ob_start();

    try {
        $router = require dirname(__DIR__) . '/bootstrap/routes.php';
    } finally {
        $output = ob_get_clean();
    }

    routesBootstrapCheck(
        $output === '',
        'Route bootstrap must not produce output.',
    );

    if (! $router instanceof Router) {
        throw new RuntimeException(
            'Route bootstrap must return a Router.',
        );
    }

    return $router;
}

$first = loadApplicationRoutes();
$second = loadApplicationRoutes();

routesBootstrapCheck(
    $first !== $second,
    'Each bootstrap must construct an independent router.',
);

routesBootstrapCheck(
    $first->isFrozen() && $second->isFrozen(),
    'Application routers must be frozen after registration.',
);

routesBootstrapCheck(
    $first->describe() === $second->describe(),
    'Independent routers must expose the same route definitions.',
);

$before = $first->describe();
$rejected = false;

try {
    $first->add(
        'GET',
        '/late-registration',
        static fn (Request $request): Response =>
            ResponseFactory::text('late'),
        'late.registration',
    );
} catch (LogicException $exception) {
    $rejected = true;

    routesBootstrapCheck(
        $exception->getMessage()
            === 'Routes cannot be registered after the router is frozen.',
        'Late registration failed for an unexpected reason.',
    );
}

routesBootstrapCheck(
    $rejected,
    'Application bootstrap must prevent late route registration.',
);

routesBootstrapCheck(
    $first->describe() === $before,
    'Rejected registration must preserve existing route definitions.',
);

routesBootstrapCheck(
    $first->path('health') === '/health',
    'Frozen application routes must support URL generation.',
);

routesBootstrapCheck(
    $second->target(
        'api.v1.greeting.show',
        ['name' => 'Ada-Lovelace'],
        ['style' => 'formal'],
    ) === '/api/v1/hello/Ada-Lovelace?style=formal',
    'Frozen application routes must preserve constrained URL generation.',
);

echo 'PASS: Silent route bootstrap, frozen registration, independence, and URL generation.'
    . PHP_EOL;

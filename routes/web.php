<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use App\Http\Handlers\HealthHandler;
use App\Http\Handlers\HomeHandler;
use App\Http\Handlers\JsonGreetingHandler;
use App\Http\Middleware\NoStoreMiddleware;
use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Http\ContainerRequestHandler;
use Flint\Routing\Router;

return static function (
    Router $router,
    Configuration $configuration,
    ?Container $container = null,
): void {
    // Preserve standalone two-argument route registration.
    $container ??= new Container();

    if (!$container->has(Configuration::class)) {
        $container->instance(Configuration::class, $configuration);
    }

    foreach ([
        HomeHandler::class,
        HealthHandler::class,
        GreetingHandler::class,
        JsonGreetingHandler::class,
    ] as $handlerClass) {
        // Explicit application bindings take precedence.
        if (!$container->has($handlerClass)) {
            $container->autowire($handlerClass);
        }
    }

    $router->addHandler(
        'GET',
        '/health',
        new ContainerRequestHandler($container, HealthHandler::class),
        'health',
    );

    $router->addHandler(
        'GET',
        '/',
        new ContainerRequestHandler($container, HomeHandler::class),
        'home',
    );

    $router->addHandler(
        'GET',
        '/hello/{name}',
        new ContainerRequestHandler($container, GreetingHandler::class),
        'greeting',
    );

    $router->addHandler(
        'POST',
        '/hello',
        new ContainerRequestHandler($container, JsonGreetingHandler::class),
        'greeting.create',
        new NoStoreMiddleware(),
    );
};

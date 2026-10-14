<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use App\Http\Handlers\HealthHandler;
use App\Http\Handlers\HomeHandler;
use App\Http\Handlers\JsonGreetingHandler;
use App\Http\Middleware\NoStoreMiddleware;
use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Http\ContainerMiddleware;
use Flint\Http\ContainerRequestHandler;
use Flint\Routing\Router;

return static function (
    Router $router,
    Configuration $configuration,
    ?Container $container = null,
): void {
    $container ??= new Container();

    if (!$container->has(Configuration::class)) {
        $container->instance(Configuration::class, $configuration);
    }

    foreach (
        [
            HomeHandler::class,
            HealthHandler::class,
            GreetingHandler::class,
            JsonGreetingHandler::class,
            NoStoreMiddleware::class,
        ] as $serviceClass
    ) {
        if (!$container->has($serviceClass)) {
            $container->autowire($serviceClass);
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
        new ContainerMiddleware($container, NoStoreMiddleware::class),
    );

    $api = $router->group(
        '/api',
        'api.',
        new ContainerMiddleware($container, NoStoreMiddleware::class),
    );
    $v1 = $api->group('/v1', 'v1.');

    $v1->addHandler(
        'GET',
        '/health',
        new ContainerRequestHandler($container, HealthHandler::class),
        'health',
    );

    $v1->addHandler(
        'POST',
        '/hello',
        new ContainerRequestHandler($container, JsonGreetingHandler::class),
        'greeting.create',
    );
};

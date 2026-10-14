<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use App\Http\Handlers\JsonGreetingHandler;
use Flint\Configuration\Configuration;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;

return static function (
    Router $router,
    Configuration $configuration,
): void {
    $router->add(
        'GET',
        '/health',
        static fn (Request $request): Response => ResponseFactory::json([
            'status' => 'ok',
        ]),
        name: 'health',
    );

    $router->add(
        'GET',
        '/',
        static fn (Request $request): Response => ResponseFactory::text(
            $configuration->string('app.name'),
        ),
        name: 'home',
    );

    $greeting = new GreetingHandler();

    $router->add(
        'GET',
        '/hello/{name}',
        $greeting->handle(...),
        name: 'greeting',
    );

     $jsonGreeting = new JsonGreetingHandler();

    $router->add(
        'POST',
        '/hello',
        $jsonGreeting->handle(...),
        name: 'greeting.create',
    );
};

<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;

return static function (Router $router,Configuration $configuration,): void 
{
    $router->add(
        'GET',
        '/health',
        static fn (Request $request): Response => ResponseFactory::json([
            'status' => 'ok',
        ]),
    );

    $router->add(
        'GET',
        '/',
        static fn (Request $request): Response => ResponseFactory::text(
            $configuration->string('app.name'),
        ),
    );
};

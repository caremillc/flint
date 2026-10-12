<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;

$application = require __DIR__ . '/app.php';

if (! $application instanceof Application) {
    throw new RuntimeException('Application bootstrap returned an invalid value.');
}

$configuration = $application->container()->get(Configuration::class);

if (! $configuration instanceof Configuration) {
    throw new RuntimeException('Application configuration is unavailable.');
}

$router = new Router();

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

return $router;

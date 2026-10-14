<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Routing\Router;

$application = require __DIR__ . '/app.php';

if (!$application instanceof Application) {
    throw new RuntimeException('Application bootstrap returned an invalid value.');
}

$container = $application->container();
$configuration = $container->get(Configuration::class);

if (!$configuration instanceof Configuration) {
    throw new RuntimeException('Application configuration is unavailable.');
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('The route file must return a registration closure.');
}

$router = new Router();

$registerRoutes($router, $configuration, $container);

$middleware = require __DIR__ . '/middleware.php';

if (!is_array($middleware) || !array_is_list($middleware)) {
    throw new RuntimeException('Middleware configuration must return a list.');
}

foreach ($middleware as $layer) {
    if (!$layer instanceof Middleware) {
        throw new RuntimeException(
            'Every configured middleware must implement Flint\Http\Middleware.',
        );
    }
}

return new MiddlewarePipeline($router, ...$middleware);

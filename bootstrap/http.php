<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use Flint\Routing\Router;

$application = require __DIR__ . '/app.php';

if (! $application instanceof Application) {
    throw new RuntimeException('Application bootstrap returned an invalid value.');
}

$configuration = $application->container()->get(Configuration::class);

if (! $configuration instanceof Configuration) {
    throw new RuntimeException('Application configuration is unavailable.');
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (! $registerRoutes instanceof Closure) {
    throw new RuntimeException('The route file must return a registration closure.');
}

$router = new Router();

$registerRoutes($router, $configuration);

return $router;
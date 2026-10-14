<?php

declare(strict_types=1);

use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Routing\Router;

$router = require __DIR__ . '/routes.php';

if (!$router instanceof Router) {
    throw new RuntimeException('Route bootstrap must return a Router.');
}

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
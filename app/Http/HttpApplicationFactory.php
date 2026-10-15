<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\RequestHandler;
use Flint\Routing\Router;
use Flint\Routing\RouteUrlGenerator;
use LogicException;
use RuntimeException;

final class HttpApplicationFactory
{
    public static function routes(Application $application): Router
    {
        if (!$application->isBooted()) {
            throw new LogicException(
                'HTTP construction requires a booted application.',
            );
        }

        $container = $application->container();
        $configuration = $container->get(Configuration::class);

        if (!$configuration instanceof Configuration) {
            throw new RuntimeException(
                'Application configuration is unavailable.',
            );
        }

        $registerRoutes = require $application->basePath()
            . '/routes/web.php';

        if (!$registerRoutes instanceof Closure) {
            throw new RuntimeException(
                'The route file must return a registration closure.',
            );
        }

        $router = new Router();

        $container->instance(
            RouteUrlGenerator::class,
            new RouteUrlGenerator($router),
        );

        $registerRoutes($router, $configuration, $container);

        $router->freeze();

        return $router;
    }

    public static function handler(Application $application): RequestHandler
    {
        $router = self::routes($application);

        $middleware = require $application->basePath()
            . '/bootstrap/middleware.php';

        if (!is_array($middleware) || !array_is_list($middleware)) {
            throw new RuntimeException(
                'Middleware configuration must return a list.',
            );
        }

        foreach ($middleware as $layer) {
            if (!$layer instanceof Middleware) {
                throw new RuntimeException(
                    'Every configured middleware must implement Flint\Http\Middleware.',
                );
            }
        }

        return new MiddlewarePipeline($router, ...$middleware);
    }
}

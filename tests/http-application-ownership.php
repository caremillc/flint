<?php

declare(strict_types=1);

use App\Http\HttpApplicationFactory;
use Flint\Foundation\Application;
use Flint\Http\Request;
use Flint\Routing\RouteUrlGenerator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function httpOwnershipCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$application = require dirname(__DIR__) . '/bootstrap/app.php';

httpOwnershipCheck(
    $application instanceof Application,
    'Bootstrap must return an application.',
);

if (!$application instanceof Application) {
    throw new RuntimeException('Invalid application.');
}

$cleanups = 0;

$application->onTerminate(
    static function () use (&$cleanups): void {
        ++$cleanups;
    },
);

try {
    httpOwnershipCheck(
        !$application->container()->has(RouteUrlGenerator::class),
        'Route services must not exist before HTTP construction.',
    );

    $handler = HttpApplicationFactory::handler($application);

    httpOwnershipCheck(
        $application->container()->has(RouteUrlGenerator::class),
        'HTTP construction must register services in the supplied application.',
    );

    foreach (['/health', '/missing', '/health'] as $target) {
        $response = $handler->handle(new Request(target: $target));

        httpOwnershipCheck(
            $response->status() === ($target === '/missing' ? 404 : 200),
            'Unexpected response for ' . $target,
        );

        httpOwnershipCheck(
            $application->isBooted()
                && !$application->isTerminated()
                && $cleanups === 0,
            'Reusable handling must not terminate the owning application.',
        );
    }
} finally {
    $application->terminate();
}

httpOwnershipCheck(
    $application->isTerminated() && $cleanups === 1,
    'The owner must terminate the application exactly once.',
);

echo 'PASS: Explicit HTTP application ownership, reusable handling, and cleanup.'
    . PHP_EOL;
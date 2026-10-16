<?php

declare(strict_types=1);

use App\Database\DatabaseConnections;
use App\Providers\ConfigurationServiceProvider;
use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use Flint\Foundation\ApplicationRunException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application(dirname(__DIR__));

try {
    $configuration = require __DIR__ . '/configuration.php';

    if (!$configuration instanceof Configuration) {
        throw new RuntimeException(
            'The configuration bootstrap must return a Configuration object.',
        );
    }

    $application->register(
        new ConfigurationServiceProvider($configuration),
    );
    // add db connection
    $application->container()->instance(
        DatabaseConnections::class,
        new DatabaseConnections($configuration),
    );
        // end db connection
    $application->boot();
} catch (Throwable $initializationFailure) {
    try {
        $application->terminate();
    } catch (Throwable $terminationFailure) {
        throw new ApplicationRunException(
            $initializationFailure,
            $terminationFailure,
        );
    }

    throw $initializationFailure;
}

return $application;

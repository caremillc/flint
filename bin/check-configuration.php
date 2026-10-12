<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Foundation\Application;

$application = require dirname(__DIR__) . '/bootstrap/app.php';

if (!$application instanceof Application) {
    throw new RuntimeException(
        'The application bootstrap must return an Application object.',
    );
}

$configuration = $application->container()->get(Configuration::class);

if (!$configuration instanceof Configuration) {
    throw new RuntimeException(
        'The application container must return a Configuration object.',
    );
}

echo 'Application: '
    . $configuration->string('app.name')
    . PHP_EOL;

echo 'Debug: '
    . ($configuration->boolean('app.debug') ? 'true' : 'false')
    . PHP_EOL;

echo 'Database port: '
    . $configuration->integer('database.port')
    . PHP_EOL;
    
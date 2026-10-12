<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Container\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

// Create the application container.
$container = new Container();

// Load the configuration object returned by the bootstrap file.
$configuration = require dirname(__DIR__) . '/bootstrap/configuration.php';

if (!$configuration instanceof Configuration) {
    throw new RuntimeException(
        'The configuration bootstrap must return a Configuration object.',
    );
}

// Register that object in the container.
$container->instance(Configuration::class, $configuration);

// Retrieve it to verify registration.
$resolved = $container->get(Configuration::class);

if (!$resolved instanceof Configuration) {
    throw new RuntimeException(
        'The container must return a Configuration object.',
    );
}

// Print the example settings.
echo 'Application: ' . $resolved->string('app.name') . PHP_EOL;

echo 'Debug: '
    . ($resolved->boolean('app.debug') ? 'true' : 'false')
    . PHP_EOL;

echo 'Database port: '
    . $resolved->integer('database.port')
    . PHP_EOL;

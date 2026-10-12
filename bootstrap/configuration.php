<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Configuration\Environment;

// Capture only the environment variables this application uses.
$captured = [];

foreach (['APP_NAME', 'APP_DEBUG', 'DATABASE_PORT'] as $name) {
    $value = getenv($name);

    if ($value !== false) {
        $captured[$name] = $value;
    }
}

$environment = new Environment($captured);

// Include only variables that are actually present.
$environmentOverrides = [];

if ($environment->has('APP_NAME')) {
    $environmentOverrides['app.name'] =
        $environment->string('APP_NAME');
}

if ($environment->has('APP_DEBUG')) {
    $environmentOverrides['app.debug'] =
        $environment->boolean('APP_DEBUG');
}

if ($environment->has('DATABASE_PORT')) {
    $environmentOverrides['database.port'] =
        $environment->integer('DATABASE_PORT');
}

// Later layers override earlier layers.
return Configuration::fromLayers(
    [
        'app.name' => 'Flint',
        'app.debug' => false,
        'database.port' => 3306,
    ],
    $environmentOverrides,
);

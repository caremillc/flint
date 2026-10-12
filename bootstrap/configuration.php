<?php

declare(strict_types=1);

use Flint\Configuration\Environment;
use Flint\Configuration\PhpConfigurationLoader;

$defaults = new PhpConfigurationLoader()->load(
    dirname(__DIR__) . '/config/defaults.php',
);

$captured = [];

foreach (['APP_NAME', 'APP_DEBUG', 'DATABASE_PORT'] as $name) {
    $value = getenv($name);

    if ($value !== false) {
        $captured[$name] = $value;
    }
}

$environment = new Environment($captured);
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

return $defaults->withOverrides($environmentOverrides);

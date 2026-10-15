<?php

declare(strict_types=1);

use App\Configuration\ApplicationConfigurationValidator;
use Flint\Configuration\Environment;
use Flint\Configuration\PhpConfigurationLoader;

$defaults = new PhpConfigurationLoader()->load(
    dirname(__DIR__) . '/config/defaults.php',
);

$captured = [];

foreach ([
    'APP_NAME',
    'APP_DEBUG',
    'DATABASE_PORT',
    'JSON_MAX_BODY_BYTES',
] as $name) {
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

if ($environment->has('JSON_MAX_BODY_BYTES')) {
    $environmentOverrides['http.json.max_body_bytes'] =
        $environment->integer('JSON_MAX_BODY_BYTES');
}

$configuration = $defaults->withOverrides($environmentOverrides);

ApplicationConfigurationValidator::validate($configuration);

return $configuration;

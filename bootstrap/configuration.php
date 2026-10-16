<?php

declare(strict_types=1);

use App\Configuration\ApplicationConfigurationValidator;
use App\Configuration\EnvironmentLoader;
use Flint\Configuration\Environment;
use Flint\Configuration\PhpConfigurationLoader;

$root = dirname(__DIR__);

$defaults = new PhpConfigurationLoader()->load(
    $root . '/config/defaults.php',
);

$names = [
    'APP_NAME',
    'APP_DEBUG',
    'DATABASE_NAME',
    'DATABASE_USERNAME',
    'DATABASE_PASSWORD',
    'DATABASE_PORT',
    'JSON_MAX_BODY_BYTES',
];

$captured = [];

foreach ($names as $name) {
    $value = getenv($name);

    if ($value !== false) {
        $captured[$name] = $value;
    }
}

// This switch is process-only; .env cannot override it.
$dotenvSwitch = getenv('FLINT_LOAD_DOTENV');

$loadDotenv = $dotenvSwitch === false
    ? true
    : (new Environment([
        'FLINT_LOAD_DOTENV' => $dotenvSwitch,
    ]))->boolean('FLINT_LOAD_DOTENV');

$environment = (new EnvironmentLoader())->load(
    $root,
    $captured,
    $loadDotenv,
);

$overrides = [];

foreach ([
    'APP_NAME' => 'app.name',
    'DATABASE_NAME' => 'database.name',
    'DATABASE_USERNAME' => 'database.username',
    'DATABASE_PASSWORD' => 'database.password',
] as $variable => $key) {
    if ($environment->has($variable)) {
        $overrides[$key] = $environment->string($variable);
    }
}

if ($environment->has('APP_DEBUG')) {
    $overrides['app.debug'] = $environment->boolean('APP_DEBUG');
}

foreach ([
    'DATABASE_PORT' => 'database.port',
    'JSON_MAX_BODY_BYTES' => 'http.json.max_body_bytes',
] as $variable => $key) {
    if ($environment->has($variable)) {
        $overrides[$key] = $environment->integer($variable);
    }
}

$configuration = $defaults->withOverrides($overrides);

ApplicationConfigurationValidator::validate($configuration);

return $configuration;

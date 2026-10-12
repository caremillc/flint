<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;
use Flint\Configuration\Exception\ConfigurationException;
use Flint\Foundation\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$bootstrap = dirname(__DIR__) . '/bootstrap/app.php';

/**
 * Throw explicitly so checks run even when PHP assertions are disabled.
 */
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$boot = static function () use ($bootstrap): Application {
    // Execute the bootstrap again for each independent application.
    $application = require $bootstrap;

    if (!$application instanceof Application) {
        throw new RuntimeException(
            'Bootstrap must return an Application object.',
        );
    }

    return $application;
};

$configurationOf = static function (
    Application $application,
): Configuration {
    $configuration = $application->container()->get(Configuration::class);

    if (!$configuration instanceof Configuration) {
        throw new RuntimeException(
            'Application configuration must be registered.',
        );
    }

    return $configuration;
};

$setEnvironment = static function (string $assignment): void {
    if (!putenv($assignment)) {
        throw new RuntimeException(
            'Could not update the test process environment.',
        );
    }
};

// Preserve the environment of this PHP process.
$original = [];

foreach (['APP_NAME', 'APP_DEBUG', 'DATABASE_PORT'] as $name) {
    $original[$name] = getenv($name);
}

try {
    $setEnvironment('APP_NAME=First application');
    $setEnvironment('APP_DEBUG=false');
    $setEnvironment('DATABASE_PORT=3306');

    $first = $boot();
    $firstConfiguration = $configurationOf($first);

    $check(
        $firstConfiguration->string('app.name') === 'First application',
        'The first application must receive its environment name.',
    );
    $check(
        $firstConfiguration->boolean('app.debug') === false,
        'The string "false" must disable debugging.',
    );
    $check(
        $firstConfiguration->integer('database.port') === 3306,
        'The first application must receive its environment port.',
    );

    $setEnvironment('APP_NAME=Second application');
    $setEnvironment('APP_DEBUG=true');
    $setEnvironment('DATABASE_PORT=5432');

    $second = $boot();
    $secondConfiguration = $configurationOf($second);

    $check(
        $first !== $second,
        'Each bootstrap must create an independent application.',
    );
    $check(
        $first->container() !== $second->container(),
        'Applications must have independent containers.',
    );
    $check(
        $firstConfiguration !== $secondConfiguration,
        'Applications must have independent configuration objects.',
    );

    $check(
        $secondConfiguration->string('app.name') === 'Second application',
        'The second application must receive the updated name.',
    );
    $check(
        $secondConfiguration->boolean('app.debug') === true,
        'The second application must receive the updated debug setting.',
    );
    $check(
        $secondConfiguration->integer('database.port') === 5432,
        'The second application must receive the updated port.',
    );

    // Later environment changes must not alter an earlier snapshot.
    $check(
        $firstConfiguration->string('app.name') === 'First application',
        'The first application name must remain unchanged.',
    );
    $check(
        $firstConfiguration->boolean('app.debug') === false,
        'The first application debug setting must remain unchanged.',
    );
    $check(
        $firstConfiguration->integer('database.port') === 3306,
        'The first application port must remain unchanged.',
    );

    // Invalid input must fail startup instead of using a default.
    $invalidCases = [
        'invalid boolean' => [
            'APP_DEBUG=invalid',
            'Environment variable "APP_DEBUG" is not a valid boolean.',
        ],
        'empty application name' => [
            'APP_NAME=',
            'Configuration key "app.name" must not be blank.',
        ],
        'blank application name' => [
            'APP_NAME=   ',
            'Configuration key "app.name" must not be blank.',
        ],
        'zero database port' => [
            'DATABASE_PORT=0',
            'Configuration key "database.port" must be between 1 and 65535.',
        ],
        'database port above maximum' => [
            'DATABASE_PORT=65536',
            'Configuration key "database.port" must be between 1 and 65535.',
        ],
        'non-integer database port' => [
            'DATABASE_PORT=3306.5',
            'Environment variable "DATABASE_PORT" is not a valid integer.',
        ],
    ];

    foreach ($invalidCases as $label => [$assignment, $expectedMessage]) {
        // Start each case with a known valid environment.
        $setEnvironment('APP_NAME=Recovery application');
        $setEnvironment('APP_DEBUG=false');
        $setEnvironment('DATABASE_PORT=3306');

        $setEnvironment($assignment);

        $rejected = false;

        try {
            $boot();
        } catch (ConfigurationException $exception) {
            $rejected = true;

            $check(
                $exception->getMessage() === $expectedMessage,
                sprintf(
                    'Unexpected startup diagnostic for case "%s".',
                    $label,
                ),
            );
        }

        $check(
            $rejected,
            sprintf(
                'Startup must reject case "%s".',
                $label,
            ),
        );

        // Verify recovery after each failure.
        $setEnvironment('APP_NAME=Recovery application');
        $setEnvironment('APP_DEBUG=false');
        $setEnvironment('DATABASE_PORT=3306');

        $recovered = $boot();
        $recoveredConfiguration = $configurationOf($recovered);

        $check(
            $recoveredConfiguration->string('app.name')
                === 'Recovery application',
            'Recovery must load the corrected application name.',
        );
        $check(
            $recoveredConfiguration->boolean('app.debug') === false,
            'Recovery must load the corrected debug setting.',
        );
        $check(
            $recoveredConfiguration->integer('database.port') === 3306,
            'Recovery must load the corrected database port.',
        );
    }


} finally {
    foreach ($original as $name => $value) {
        $setEnvironment(
            $value === false ? $name : $name . '=' . $value,
        );
    }
}

echo 'PASS: Application configuration isolation and 6 startup rejection/recovery cases.'
    . PHP_EOL;

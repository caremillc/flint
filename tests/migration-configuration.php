<?php

declare(strict_types=1);

function migrationConfigurationCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$values = [
    'FLINT_LOAD_DOTENV' => 'false',
    'APP_NAME' => 'Migration configuration test',
    'APP_DEBUG' => 'false',
    'DATABASE_NAME' => 'flint_migration_test',
    'DATABASE_USERNAME' => 'migration_test_user',
    'DATABASE_PASSWORD' => 'configuration-test-secret',
    'DATABASE_PORT' => '3307',
    'JSON_MAX_BODY_BYTES' => '4096',
];

$original = [];

foreach ($values as $name => $value) {
    $original[$name] = getenv($name);
}

try {
    foreach ($values as $name => $value) {
        if (!putenv($name . '=' . $value)) {
            throw new RuntimeException(
                'Could not configure the migration test environment.',
            );
        }
    }

    ob_start();

    try {
        $settings = require dirname(__DIR__) . '/phinx.php';
    } finally {
        $output = ob_get_clean();
    }

    migrationConfigurationCheck(
        $output === '',
        'Loading migration configuration must not emit output.',
    );

    migrationConfigurationCheck(
        is_array($settings),
        'Migration configuration must return an array.',
    );

    $environment = $settings['environments']['configured'] ?? null;

    migrationConfigurationCheck(
        $environment === [
            'adapter' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3307,
            'name' => 'flint_migration_test',
            'user' => 'migration_test_user',
            'pass' => 'configuration-test-secret',
            'charset' => 'utf8mb4',
        ],
        'Migration settings must come from the typed application configuration.',
    );

    migrationConfigurationCheck(
        $settings['environments']['default_migration_table']
            === 'flint_migrations',
        'Migration history must use the designated table.',
    );

    migrationConfigurationCheck(
        $settings['environments']['default_environment'] === 'configured',
        'The selected migration environment must be explicit.',
    );

    migrationConfigurationCheck(
        is_dir($settings['paths']['migrations'])
            && is_dir($settings['paths']['seeds']),
        'Migration and seed directories must exist.',
    );
} finally {
    foreach ($original as $name => $value) {
        if (!putenv($value === false ? $name : $name . '=' . $value)) {
            throw new RuntimeException(
                'Could not restore the migration test environment.',
            );
        }
    }
}

echo 'PASS: Shared migration configuration, silent loading, and environment mapping.'
    . PHP_EOL;
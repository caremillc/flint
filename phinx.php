<?php

declare(strict_types=1);

use Flint\Configuration\Configuration;

require_once __DIR__ . '/vendor/autoload.php';

$configuration = require __DIR__ . '/bootstrap/configuration.php';

if (!$configuration instanceof Configuration) {
    throw new RuntimeException(
        'Migration configuration requires a Configuration object.',
    );
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],

    'environments' => [
        'default_migration_table' => 'flint_migrations',
        'default_environment' => 'configured',

        'configured' => [
            'adapter' => 'mysql',
            'host' => '127.0.0.1',
            'port' => $configuration->integer('database.port'),
            'name' => $configuration->string('database.name'),
            'user' => $configuration->string('database.username'),
            'pass' => $configuration->string('database.password'),
            'charset' => 'utf8mb4',
        ],
    ],

    'version_order' => 'creation',
];
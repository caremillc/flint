<?php

declare(strict_types=1);
require 'vendor/autoload.php';
try {
    $configuration = require 'bootstrap/configuration.php';
    echo 'PHP: ', PHP_VERSION, PHP_EOL;
    echo 'PDO MySQL: ',
        extension_loaded('pdo_mysql') ? 'available' : 'missing',
        PHP_EOL;
    if (! extension_loaded('pdo_mysql')) {
        exit(1);
    }
    $database = $configuration->string('database.name');
    $username = $configuration->string('database.username');
    $password = $configuration->string('database.password');
    $port = $configuration->integer('database.port');
    echo 'Host: 127.0.0.1', PHP_EOL;
    echo 'Port: ', $port, PHP_EOL;
    echo 'Database: ', $database, PHP_EOL;
    echo 'Username: ', $username, PHP_EOL;
    echo 'Password configured: ', $password !== '' ? 'yes' : 'no', PHP_EOL;
    foreach ([
        'FLINT_LOAD_DOTENV',
        'DATABASE_NAME',
        'DATABASE_USERNAME',
        'DATABASE_PASSWORD',
        'DATABASE_PORT',
    ] as $name) {
        echo $name, ' process override: ',
            getenv($name) === false ? 'absent' : 'present',
            PHP_EOL;
    }
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $database) !== 1) {
        throw new RuntimeException('Invalid database name.');
    }
    $connection = new PDO(
        sprintf(
            'mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4',
            $port,
            $database,
        ),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ],
    );
    echo 'PASS: Configured connection succeeded.', PHP_EOL;
    echo 'Server version: ',
        $connection->query('SELECT VERSION()')->fetchColumn(),
        PHP_EOL;
} catch (PDOException $exception) {
    echo 'FAIL: Database connection or diagnostic query failed.', PHP_EOL;
    echo 'SQLSTATE: ',
        $exception->errorInfo[0] ?? $exception->getCode(),
        PHP_EOL;
    echo 'Driver error number: ',
        $exception->errorInfo[1] ?? 'unavailable',
        PHP_EOL;

    exit(1);
} catch (Throwable $exception) {
    echo 'FAIL: Diagnostic configuration/runtime error: ',
        $exception::class,
        PHP_EOL;

    exit(1);
}


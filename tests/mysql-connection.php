<?php

declare(strict_types=1);

use Flint\Database\Exception\DatabaseConnectionException;
use Flint\Database\MySqlConnectionFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function mysqlConnectionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mysqlTestEnvironment(string $name): string
{
    $value = getenv($name);

    if ($value === false) {
        throw new RuntimeException(
            'Missing integration-test environment variable: ' . $name,
        );
    }

    return $value;
}

if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException(
        'Enable pdo_mysql for the CLI PHP runtime before running this test.',
    );
}

$database = mysqlTestEnvironment('FLINT_TEST_DATABASE');
$username = mysqlTestEnvironment('FLINT_TEST_DATABASE_USER');
$password = mysqlTestEnvironment('FLINT_TEST_DATABASE_PASSWORD');

$portValue = getenv('FLINT_TEST_DATABASE_PORT');

if ($portValue === false) {
    $portValue = '3306';
}

$port = filter_var(
    $portValue,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 65535,
        ],
    ],
);

if ($port === false) {
    throw new RuntimeException(
        'FLINT_TEST_DATABASE_PORT must be an integer between 1 and 65535.',
    );
}

$factory = new MySqlConnectionFactory();

$connection = $factory->connect(
    $database,
    $username,
    $password,
    $port,
);

mysqlConnectionCheck(
    $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql',
    'The connection must use PDO MySQL.',
);

mysqlConnectionCheck(
    (int) $connection->getAttribute(PDO::ATTR_ERRMODE)
        === PDO::ERRMODE_EXCEPTION,
    'Database errors must use exceptions.',
);

mysqlConnectionCheck(
    !$connection->getAttribute(PDO::ATTR_EMULATE_PREPARES),
    'Prepared-statement emulation must be disabled.',
);

$statement = $connection->query(
    'SELECT @@character_set_connection AS connection_charset',
);

if ($statement === false) {
    throw new RuntimeException('Could not inspect the connection charset.');
}

mysqlConnectionCheck(
    $statement->fetchColumn() === 'utf8mb4',
    'The connection must use utf8mb4.',
);

$statement->closeCursor();

$value = "Ada's task — مرحباً 😀 ; DROP TABLE tasks;";

$statement = $connection->prepare('SELECT :value AS supplied_value');

if ($statement === false) {
    throw new RuntimeException('Could not prepare the parameter-binding check.');
}

$statement->bindValue(':value', $value, PDO::PARAM_STR);
$statement->execute();

mysqlConnectionCheck(
    $statement->fetchColumn() === $value,
    'Bound input must round-trip as data with its UTF-8 bytes preserved.',
);

$statement->closeCursor();
unset($statement);

// A random nonexistent database exercises connection failure.
// This test creates no databases and modifies no tables.
$missingDatabase = 'flint_missing_' . bin2hex(random_bytes(12));
$caught = null;

try {
    $factory->connect(
        $missingDatabase,
        $username,
        $password,
        $port,
    );
} catch (DatabaseConnectionException $exception) {
    $caught = $exception;
}

mysqlConnectionCheck(
    $caught instanceof DatabaseConnectionException,
    'Connection to a nonexistent database must fail safely.',
);

if (!$caught instanceof DatabaseConnectionException) {
    throw new RuntimeException('Expected a connection failure.');
}

mysqlConnectionCheck(
    $caught->getMessage()
        === 'MySQL connection failed. Check the service, database, credentials, and permissions.',
    'Connection failure must expose only the fixed diagnostic.',
);

mysqlConnectionCheck(
    $caught->getPrevious() === null,
    'Connection failure must not attach unrestricted driver diagnostics.',
);

// Failure on a separate attempt must not damage an existing connection.
$statement = $connection->query('SELECT 1');

if ($statement === false) {
    throw new RuntimeException('Could not execute the recovery check.');
}

mysqlConnectionCheck(
    (string) $statement->fetchColumn() === '1',
    'The existing connection must remain usable after another attempt fails.',
);

$statement->closeCursor();
unset($statement, $connection, $password);

echo 'PASS: MySQL connection, utf8mb4, bound values, safe failure, and recovery.'
    . PHP_EOL;

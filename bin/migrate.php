<?php

declare(strict_types=1);

use App\Database\DatabaseConnections;
use Flint\Database\MySqlMigrationRunner;
use Flint\Foundation\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php bin/migrate.php\n");
    exit(2);
}

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';

    if (!$application instanceof Application) {
        throw new RuntimeException('Invalid application bootstrap.');
    }

    $applied = $application->run(
        static function (Application $running): array {
            $connections = $running->container()->get(
                DatabaseConnections::class,
            );

            if (!$connections instanceof DatabaseConnections) {
                throw new RuntimeException('Database connections are unavailable.');
            }

            $connection = $connections->connect();

            return (new MySqlMigrationRunner($connection))->migrate(
                $running->basePath() . '/database/migrations',
            );
        },
    );

    foreach ($applied as $id) {
        fwrite(STDOUT, 'APPLIED: ' . $id . PHP_EOL);
    }

    fwrite(
        STDOUT,
        sprintf("PASS: %d migration(s) applied.\n", count($applied)),
    );
} catch (Throwable $exception) {
    // Do not print driver messages, SQL, credentials, or stack traces.
    fwrite(
        STDERR,
        "FAIL: Migration execution stopped. Inspect database connectivity, "
        . "migration files, and flint_migrations history before retrying.\n",
    );

    exit(1);
}

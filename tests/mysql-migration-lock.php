<?php

declare(strict_types=1);

use App\Database\DatabaseConnections;
use Flint\Database\Exception\MigrationLockException;
use Flint\Database\MySqlMigrationLock;
use Flint\Foundation\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function migrationLockCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$application = require dirname(__DIR__) . '/bootstrap/app.php';

if (!$application instanceof Application) {
    throw new RuntimeException('Invalid application bootstrap.');
}

$application->run(
    static function (Application $running): void {
        $connections = $running->container()->get(DatabaseConnections::class);

        if (!$connections instanceof DatabaseConnections) {
            throw new RuntimeException('Database connections are unavailable.');
        }

        $firstConnection = $connections->connect();
        $secondConnection = $connections->connect();

        $first = new MySqlMigrationLock($firstConnection);
        $second = new MySqlMigrationLock($secondConnection);

        try {
            $first->acquire();

            $blocked = false;

            try {
                $second->acquire();
            } catch (MigrationLockException) {
                $blocked = true;
            }

            migrationLockCheck(
                $blocked,
                'A second connection must not acquire the active migration lock.',
            );

            $recursive = new MySqlMigrationLock($firstConnection);
            $rejected = false;

            try {
                $recursive->acquire();
            } catch (LogicException) {
                $rejected = true;
            }

            migrationLockCheck(
                $rejected,
                'Another object on the same connection must not acquire recursively.',
            );

            $first->release();

            // Releasing twice must not interfere with another owner.
            $second->acquire();
            $first->release();

            $blocked = false;

            try {
                $first->acquire();
            } catch (MigrationLockException) {
                $blocked = true;
            }

            migrationLockCheck(
                $blocked,
                'Repeated release must not release another connection’s lock.',
            );

            $second->release();

            // Simulate failed migration work with explicit release.
            $failure = new RuntimeException('Controlled migration failure.');
            $caught = null;

            try {
                $first->acquire();

                try {
                    throw $failure;
                } finally {
                    $first->release();
                }
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            migrationLockCheck(
                $caught === $failure,
                'Successful release must preserve the operation failure.',
            );

            $second->acquire();
            $second->release();
        } finally {
            try {
                $first->release();
            } finally {
                $second->release();
            }
        }
    },
);

migrationLockCheck(
    $application->isTerminated(),
    'The lock test must terminate its application.',
);

echo 'PASS: Migration lock contention, recursive rejection, ownership, and recovery.'
    . PHP_EOL;

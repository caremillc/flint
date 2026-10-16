<?php

declare(strict_types=1);

use App\Database\DatabaseConnections;
use Flint\Database\TransactionManager;
use Flint\Foundation\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function transactionCheck(bool $condition, string $message): void
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

        $connection = $connections->connect();
        $transactions = new TransactionManager($connection);

        // Generated internally, never derived from user input.
        $table = 'flint_tx_' . bin2hex(random_bytes(8));

        $connection->exec(
            "CREATE TEMPORARY TABLE `$table` (
                id INT NOT NULL PRIMARY KEY,
                title VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB",
        );

        $insert = static function (
            PDO $pdo,
            int $id,
            string $title,
        ) use ($table): void {
            $statement = $pdo->prepare(
                "INSERT INTO `$table` (id, title) VALUES (:id, :title)",
            );

            if ($statement === false) {
                throw new RuntimeException('Could not prepare the insert.');
            }

            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->bindValue(':title', $title, PDO::PARAM_STR);
            $statement->execute();
            $statement->closeCursor();
        };

        $ids = static function () use ($connection, $table): array {
            $statement = $connection->query(
                "SELECT id FROM `$table` ORDER BY id",
            );

            if ($statement === false) {
                throw new RuntimeException('Could not inspect transaction results.');
            }

            $values = $statement->fetchAll(PDO::FETCH_COLUMN);
            $statement->closeCursor();

            return array_map(
                static fn (mixed $value): int => (int) $value,
                $values,
            );
        };

        // Successful work commits and preserves the callback result.
        $result = $transactions->run(
            static function (PDO $pdo) use ($insert): string {
                $insert($pdo, 1, 'Committed');

                return 'created';
            },
        );

        transactionCheck(
            $result === 'created'
                && $ids() === [1]
                && !$connection->inTransaction(),
            'Successful work must commit and return its result.',
        );

        // Application failures roll back and retain exception identity.
        $failure = new RuntimeException('Controlled operation failure.');
        $caught = null;

        try {
            $transactions->run(
                static function (PDO $pdo) use ($insert, $failure): void {
                    $insert($pdo, 2, 'Must roll back');

                    throw $failure;
                },
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        transactionCheck(
            $caught === $failure
                && $ids() === [1]
                && !$connection->inTransaction(),
            'Failed work must roll back without replacing the exception.',
        );

        // Database errors must also roll back earlier work in the callback.
        $caught = null;

        try {
            $transactions->run(
                static function (PDO $pdo) use ($insert): void {
                    $insert($pdo, 3, 'Must also roll back');
                    $insert($pdo, 1, 'Duplicate primary key');
                },
            );
        } catch (PDOException $exception) {
            $caught = $exception;
        }

        transactionCheck(
            $caught instanceof PDOException
                && $ids() === [1]
                && !$connection->inTransaction(),
            'A database error must roll back preceding writes.',
        );

        // An uncaught nested-transaction rejection rolls back the outer work.
        $nestedRan = false;
        $caught = null;

        try {
            $transactions->run(
                static function (PDO $pdo) use (
                    $insert,
                    $transactions,
                    &$nestedRan,
                ): void {
                    $insert($pdo, 4, 'Outer work');

                    $transactions->run(
                        static function (PDO $nested) use (&$nestedRan): void {
                            $nestedRan = true;
                        },
                    );
                },
            );
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        transactionCheck(
            $caught instanceof LogicException
                && !$nestedRan
                && $ids() === [1]
                && !$connection->inTransaction(),
            'Nested execution must be rejected before its callback runs.',
        );

        // The manager remains usable after successful rollbacks.
        $transactions->run(
            static function (PDO $pdo) use ($insert): void {
                $insert($pdo, 5, 'Recovered');
            },
        );

        transactionCheck(
            $ids() === [1, 5] && !$connection->inTransaction(),
            'A new transaction must succeed after rollback.',
        );

        // Never take ownership of a transaction opened by another caller.
        $connection->beginTransaction();
        $caught = null;

        try {
            $transactions->run(
                static function (PDO $pdo): void {
                    throw new RuntimeException(
                        'Externally owned transaction callback must not execute.',
                    );
                },
            );
        } catch (LogicException $exception) {
            $caught = $exception;
        } finally {
            $stillActive = $connection->inTransaction();

            if ($stillActive) {
                $connection->rollBack();
            }
        }

        transactionCheck(
            $caught instanceof LogicException && $stillActive,
            'Rejection must leave an externally owned transaction untouched.',
        );

        $connection->exec("DROP TEMPORARY TABLE `$table`");
    },
);

transactionCheck(
    $application->isTerminated(),
    'The integration test must terminate its application.',
);

echo 'PASS: MySQL commit, application rollback, SQL-error rollback, '
    . 'nesting rejection, recovery, and external ownership.'
    . PHP_EOL;

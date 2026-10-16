# Database support

## Configuration

Database connections use the application Configuration snapshot.

Precedence is:
1. Process environment.
2. Optional application .env file.
3. config/defaults.php.

Supported keys:
- database.name
- database.username
- database.password
- database.port

The first adapter connects to 127.0.0.1 using PDO MySQL and utf8mb4.
It disables persistent connections and prepared-statement emulation.

DatabaseConnections::connect() opens a new connection.
Application startup does not open one automatically.

Remote hosts, TLS configuration, Unix sockets, and connection pooling
are not supported by this initial adapter.

## Parameter binding

Bind external values through PDO prepared statements.

Values cannot be used as SQL identifiers. Table names, column names,
and sort directions require trusted SQL or explicit allowlists.

## Transactions

TransactionManager::run() begins one transaction, executes a callback,
commits successful work, and returns the callback result.

Callback exceptions trigger rollback when the connection still has an
active transaction. After successful rollback, the original exception
propagates unchanged.

Nested transactions and externally owned transactions are rejected
before the callback runs. No savepoints or automatic retries are provided.

Callbacks must not:
- Commit or roll back directly.
- Start another transaction.
- Change autocommit or transaction settings.
- Execute schema changes or other implicit-commit statements.

Use transactional tables such as InnoDB.

Unexpected transaction termination, commit uncertainty, and rollback
failure make the manager unusable. Discard both the manager and its
connection. Do not automatically replay the operation.

A rollback failure produces TransactionRollbackException, retaining
both the operation and rollback failures.

## Resource ownership

The transaction manager retains its PDO connection.

Releasing one variable does not close a connection if another object or
statement still references it. Keep connections and statements within
their owning operation.

Ending a transaction does not close the connection.

## Verification

Service-independent checks:

    FLINT_LOAD_DOTENV=false composer check:all

Configured database checks:

    composer test:integration

The transaction integration test creates a connection-local temporary
InnoDB table. Its account needs CREATE TEMPORARY TABLES permission.

A MariaDB run is MariaDB evidence. MySQL support requires a recorded run
against MySQL.

Commit uncertainty and rollback failures are covered with controlled PDO
doubles; these do not prove network-failure behavior on a real server.

5. Run verification.
(
    cd /c/xampp/htdocs/flint || exit 1

    composer --working-dir=framework cs:fix &&
    composer --working-dir=framework analyse &&

    php framework/vendor/bin/phpunit \
        --configuration=framework/phpunit.xml \
        --filter='MySqlConnectionFactoryTest|TransactionManagerTest' \
        --fail-on-empty-test-suite &&

    composer analyse:application &&
    composer test:exception-reporting &&

    FLINT_LOAD_DOTENV=false composer check:all &&
    composer test:integration
)

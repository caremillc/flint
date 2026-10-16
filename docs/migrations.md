# Migration tooling

## Decision

Use Phinx in the application skeleton for migration execution and history.
Keep framework runtime code independent of Phinx.

Alternatives considered:
- A custom migration engine: rejected because history tracking, command
  behavior, and migration execution would add maintenance work.
- An ORM migration stack: deferred because Flint currently uses PDO
  repositories and does not require an ORM.

Phinx is a regular dependency because deployment requires migration tooling.
The application lockfile records the selected version.

## Configuration

phinx.php loads bootstrap/configuration.php.

Precedence remains:
1. Process environment.
2. Optional .env.
3. config/defaults.php.

The configured environment uses the same local MySQL database settings
as application connections.

Do not print or dump the migration configuration: it contains credentials.

## Ownership

Application startup never runs migrations.

Migration files are trusted executable deployment code.
Database schema changes are separate from application transactions.

The migration history table is flint_migrations.

## Current boundary

Tool installation and configuration are introduced first.

Concurrent migration execution is not yet supported or verified.
Do not run migration commands concurrently.

Before applying the initial schema, implement and test migration locking,
fresh-database installation, rerun behavior, and partial-failure handling.

Automatic rollback is not a production recovery strategy.
Schema rollback and code compatibility require a rehearsed procedure.

7. Verify the installation and configuration.
(
    cd /c/xampp/htdocs/flint || exit 1

    composer validate --strict &&
    php -l phinx.php &&
    php -l tests/migration-configuration.php &&

    php vendor/bin/phinx --version &&
    php vendor/bin/phinx help migrate &&
    composer test:migration-configuration &&

    FLINT_LOAD_DOTENV=false composer check:all &&
    composer audit
)

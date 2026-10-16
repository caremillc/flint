<?php

declare(strict_types=1);

namespace App\Database;

use Flint\Configuration\Configuration;
use Flint\Database\MySqlConnectionFactory;
use PDO;

final readonly class DatabaseConnections
{
    public function __construct(
        private Configuration $configuration,
    ) {}

    public function connect(): PDO
    {
        return (new MySqlConnectionFactory())->connect(
            database: $this->configuration->string('database.name'),
            username: $this->configuration->string('database.username'),
            password: $this->configuration->string('database.password'),
            port: $this->configuration->integer('database.port'),
        );
    }
}

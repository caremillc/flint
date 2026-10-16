<?php

declare(strict_types=1);

namespace App\Configuration;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Exception\InvalidPathException;
use Flint\Configuration\Environment;
use Flint\Configuration\Exception\ConfigurationException;

final class EnvironmentLoader
{
    /**
     * @param array<string, string> $external
     */
    public function load(
        string $directory,
        array $external,
        bool $loadDotenv = true,
    ): Environment {
        $values = [];

        if ($loadDotenv && file_exists($directory . '/.env')) {
            try {
                $values = Dotenv::createArrayBacked($directory)->load();
            } catch (InvalidFileException | InvalidPathException) {
                // Parser errors can contain fragments of secret-bearing lines.
                throw new ConfigurationException(
                    'Could not load .env. Check its syntax and read permissions.',
                );
            }
        }

        return new Environment(array_replace($values, $external));
    }
}

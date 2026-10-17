<?php

declare(strict_types=1);

namespace App\Configuration;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Flint\Configuration\Environment;
use Flint\Configuration\Exception\ConfigurationException;

final class EnvironmentLoader
{
    private const string LOAD_ERROR =
        'Could not load .env. Check its syntax and read permissions.';

    /**
     * @param array<string, string> $external
     */
    public function load(
        string $directory,
        array $external,
        bool $loadDotenv = true,
    ): Environment {
        $values = [];
        $path = $directory . '/.env';

        if ($loadDotenv && (file_exists($path) || is_link($path))) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new ConfigurationException(self::LOAD_ERROR);
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new ConfigurationException(self::LOAD_ERROR);
            }

            // The marker must not collide with any existing file content.
            do {
                $marker = 'FLINT_EOF_' . bin2hex(random_bytes(16));
            } while (str_contains($contents, $marker));

            try {
                $values = Dotenv::parse(
                    $contents . "\n" . $marker . "=complete\n",
                );
            } catch (InvalidFileException) {
                // Never expose secret-bearing parser messages or chain them.
                throw new ConfigurationException(self::LOAD_ERROR);
            }

            // An unfinished multiline value can swallow the remainder
            // of the file without the dependency throwing an exception.
            if (($values[$marker] ?? null) !== 'complete') {
                throw new ConfigurationException(self::LOAD_ERROR);
            }

            unset($values[$marker]);
        }

        return new Environment(array_replace($values, $external));
    }
}

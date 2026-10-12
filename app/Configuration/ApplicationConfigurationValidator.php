<?php

declare(strict_types=1);

namespace App\Configuration;

use Flint\Configuration\Configuration;
use Flint\Configuration\Exception\ConfigurationException;

final class ApplicationConfigurationValidator
{
    public static function validate(Configuration $configuration): void
    {
        $name = $configuration->string('app.name');

        if (trim($name) === '') {
            throw new ConfigurationException(
                'Configuration key "app.name" must not be blank.',
            );
        }

        $configuration->boolean('app.debug');

        $port = $configuration->integer('database.port');

        if ($port < 1 || $port > 65535) {
            throw new ConfigurationException(
                'Configuration key "database.port" must be between 1 and 65535.',
            );
        }
    }
}

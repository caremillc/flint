<?php

declare(strict_types=1);

use App\Providers\ConfigurationServiceProvider;
use Flint\Configuration\Configuration;
use Flint\Foundation\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application(dirname(__DIR__));

$configuration = require __DIR__ . '/configuration.php';

if (!$configuration instanceof Configuration) {
    throw new RuntimeException(
        'The configuration bootstrap must return a Configuration object.',
    );
}

$application->register(
    new ConfigurationServiceProvider($configuration),
);

$application->boot();

return $application;
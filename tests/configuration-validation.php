<?php

declare(strict_types=1);

use App\Configuration\ApplicationConfigurationValidator;
use Flint\Configuration\Configuration;
use Flint\Configuration\Exception\ConfigurationException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$defaults = new Configuration([
    'app.name' => 'Flint',
    'app.debug' => false,
    'database.port' => 3306,
]);

// Valid ports include both boundaries.
foreach ([1, 3306, 65535] as $port) {
    $configuration = $defaults->withOverrides([
        'database.port' => $port,
    ]);

    ApplicationConfigurationValidator::validate($configuration);
}

$invalidCases = [
    'empty name' => [
        'app.name' => '',
    ],
    'whitespace name' => [
        'app.name' => " \t\n",
    ],
    'null name' => [
        'app.name' => null,
    ],
    'string debug flag' => [
        'app.debug' => 'false',
    ],
    'string port' => [
        'database.port' => '3306',
    ],
    'zero port' => [
        'database.port' => 0,
    ],
    'negative port' => [
        'database.port' => -1,
    ],
    'port above maximum' => [
        'database.port' => 65536,
    ],
];

foreach ($invalidCases as $label => $overrides) {
    $configuration = $defaults->withOverrides($overrides);
    $rejected = false;

    try {
        ApplicationConfigurationValidator::validate($configuration);
    } catch (ConfigurationException) {
        $rejected = true;
    }

    if (!$rejected) {
        throw new RuntimeException(
            sprintf('Configuration validation accepted case "%s".', $label),
        );
    }
}

echo 'PASS: Application configuration validation (11 cases).'
    . PHP_EOL;

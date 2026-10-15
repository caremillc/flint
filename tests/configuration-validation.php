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
    'http.json.max_body_bytes' => 4096,
]);


$count = 0;

foreach ([1, 3306, 65535] as $port) {
    ApplicationConfigurationValidator::validate(
        $defaults->withOverrides([
            'database.port' => $port,
        ]),
    );

    ++$count;
}

foreach ([1, 4096, 1048576] as $limit) {
    ApplicationConfigurationValidator::validate(
        $defaults->withOverrides([
            'http.json.max_body_bytes' => $limit,
        ]),
    );

    ++$count;
}

$invalidCases = [
    'empty name' => ['app.name' => ''],
    'whitespace name' => ['app.name' => " \t\n"],
    'null name' => ['app.name' => null],
    'string debug flag' => ['app.debug' => 'false'],
    'string port' => ['database.port' => '3306'],
    'zero port' => ['database.port' => 0],
    'negative port' => ['database.port' => -1],
    'port above maximum' => ['database.port' => 65536],
    'string JSON limit' => ['http.json.max_body_bytes' => '4096'],
    'null JSON limit' => ['http.json.max_body_bytes' => null],
    'zero JSON limit' => ['http.json.max_body_bytes' => 0],
    'negative JSON limit' => ['http.json.max_body_bytes' => -1],
    'JSON limit above capture maximum' => [
        'http.json.max_body_bytes' => 1048577,
    ],
];

foreach ($invalidCases as $label => $overrides) {
    $rejected = false;

    try {
        ApplicationConfigurationValidator::validate(
            $defaults->withOverrides($overrides),
        );
    } catch (ConfigurationException) {
        $rejected = true;
    }

    if (! $rejected) {
        throw new RuntimeException(
            sprintf('Configuration validation accepted case "%s".', $label),
        );
    }

    ++$count;
}

echo sprintf(
    'PASS: Application configuration validation (%d cases).',
    $count,
) . PHP_EOL;

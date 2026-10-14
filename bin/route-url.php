<?php

declare(strict_types=1);

use Flint\Routing\Router;

$usage = <<<'TEXT'
Usage:
  php bin/route-url.php NAME [PARAMETERS_JSON] [QUERY_JSON]
  php bin/route-url.php --help

Examples:
  php bin/route-url.php health
  php bin/route-url.php greeting '{"name":"Ada"}'
  php bin/route-url.php api.v1.greeting.show '{"name":"Ada-Lovelace"}' '{"style":"formal"}'

Parameters and query arguments must be JSON objects.
TEXT;

$usage .= PHP_EOL;
$arguments = array_slice($argv, 1);

if ($arguments === ['--help']) {
    fwrite(STDOUT, $usage);
    exit(0);
}

if (
    count($arguments) < 1
    || count($arguments) > 3
    || $arguments[0] === ''
    || str_starts_with($arguments[0], '--')
) {
    fwrite(STDERR, $usage);
    exit(2);
}

/**
 * @return array<array-key, mixed>
 */
function decodeRouteArguments(string $json, string $label): array
{
    try {
        $decoded = json_decode(
            $json,
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        throw new InvalidArgumentException(
            $label . ' must contain valid JSON.',
            previous: $exception,
        );
    }

    if (! $decoded instanceof stdClass) {
        throw new InvalidArgumentException(
            $label . ' must be a JSON object.',
        );
    }

    return get_object_vars($decoded);
}

try {
    $parameters = decodeRouteArguments(
        $arguments[1] ?? '{}',
        'Parameters',
    );

    $query = decodeRouteArguments(
        $arguments[2] ?? '{}',
        'Query',
    );

    ob_start();

    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $router = require dirname(__DIR__) . '/bootstrap/routes.php';
    } finally {
        $startupOutput = ob_get_clean();
    }

    if ($startupOutput !== '') {
        throw new RuntimeException(
            'Route bootstrap must not produce output.',
        );
    }

    if (! $router instanceof Router) {
        throw new RuntimeException(
            'Route bootstrap must return a Router.',
        );
    }

    $target = $router->target(
        $arguments[0],
        $parameters,
        $query,
    );

    fwrite(STDOUT, $target . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

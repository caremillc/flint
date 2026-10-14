<?php

declare(strict_types=1);

use Flint\Routing\Router;

$usage = 'Usage: php bin/routes.php [--json|--help]' . PHP_EOL;
$arguments = array_slice($argv, 1);

if ($arguments === ['--help']) {
    fwrite(STDOUT, $usage);
    exit(0);
}

if ($arguments !== [] && $arguments !== ['--json']) {
    fwrite(STDERR, $usage);
    exit(2);
}

try {
    ob_start();

    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $router = require dirname(__DIR__) . '/bootstrap/routes.php';
    } finally {
        $startupOutput = ob_get_clean();
    }

    if ($startupOutput !== '') {
        throw new RuntimeException(
            'Route listing bootstrap must not produce output.',
        );
    }

    if (!$router instanceof Router) {
        throw new RuntimeException('Route bootstrap must return a Router.');
    }

    $routes = $router->describe();

    if ($arguments === ['--json']) {
        echo json_encode(
            [
                'schema_version' => 1,
                'routes' => $routes,
            ],
            JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;

        exit(0);
    }

    $rows = [
        ['METHOD', 'PATH', 'NAME', 'CONSTRAINTS'],
    ];

    foreach ($routes as $route) {
        $constraints = [];

        foreach ($route['constraints'] as $parameter => $constraint) {
            $constraints[] = $parameter . ':' . $constraint;
        }

        $rows[] = [
            $route['method'],
            $route['path'],
            $route['name'] ?? '-',
            $constraints === [] ? '-' : implode(', ', $constraints),
        ];
    }

    $methodWidth = 0;
    $pathWidth = 0;
    $nameWidth = 0;

    foreach ($rows as $row) {
        $methodWidth = max($methodWidth, strlen($row[0]));
        $pathWidth = max($pathWidth, strlen($row[1]));
        $nameWidth = max($nameWidth, strlen($row[2]));
    }

    $format = sprintf(
        '%%-%ds  %%-%ds  %%-%ds  %%s',
        $methodWidth,
        $pathWidth,
        $nameWidth,
    ) . PHP_EOL;

    foreach ($rows as $row) {
        printf($format, ...$row);
    }
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'FAIL: ' . $exception->getMessage() . PHP_EOL,
    );

    exit(1);
}

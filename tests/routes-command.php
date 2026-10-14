<?php

declare(strict_types=1);

function routesCommandCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 *
 * @return array{
 *     status: int,
 *     stdout: string,
 *     stderr: string
 * }
 */
function runRoutesCommand(
    array $arguments,
    array $environment,
): array {
    $root = dirname(__DIR__);

    $process = proc_open(
        [
            PHP_BINARY,
            $root . '/bin/routes.php',
            ...$arguments,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        $environment,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the routes command.');
    }

    try {
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_close($process);
    }

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Could not read routes command output.');
    }

    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/** @var array<string, string> $environment */
$environment = getenv();

$environment['APP_NAME'] = 'Routes command test';
$environment['APP_DEBUG'] = 'false';
$environment['DATABASE_PORT'] = '3306';

$json = runRoutesCommand(['--json'], $environment);

routesCommandCheck(
    $json['status'] === 0 && $json['stderr'] === '',
    'JSON route listing must succeed without stderr output.',
);

$document = json_decode(
    $json['stdout'],
    true,
    512,
    JSON_THROW_ON_ERROR,
);

if (
    !is_array($document)
    || ($document['schema_version'] ?? null) !== 1
    || !is_array($document['routes'] ?? null)
    || !array_is_list($document['routes'])
) {
    throw new RuntimeException('The route listing document is invalid.');
}

$named = [];

foreach ($document['routes'] as $route) {
    if (!is_array($route) || !array_key_exists('name', $route)) {
        throw new RuntimeException('A route description is invalid.');
    }

    if (is_string($route['name'])) {
        $named[$route['name']] = $route;
    }
}

$expected = [
    'home' => ['GET', '/', []],
    'health' => ['GET', '/health', []],
    'greeting' => ['GET', '/hello/{name}', []],
    'greeting.create' => ['POST', '/hello', []],
    'api.v1.health' => ['GET', '/api/v1/health', []],
    'api.v1.greeting.create' => ['POST', '/api/v1/hello', []],
    'api.v1.greeting.show' => [
        'GET',
        '/api/v1/hello/{name}',
        ['name' => 'slug'],
    ],
];

foreach ($expected as $name => [$method, $path, $constraints]) {
    $route = $named[$name] ?? null;

    routesCommandCheck(
        is_array($route)
            && ($route['method'] ?? null) === $method
            && ($route['path'] ?? null) === $path
            && ($route['constraints'] ?? null) === $constraints,
        'Unexpected route description for ' . $name . '.',
    );
}

$text = runRoutesCommand([], $environment);

routesCommandCheck(
    $text['status'] === 0
        && $text['stderr'] === ''
        && str_contains($text['stdout'], 'METHOD')
        && str_contains($text['stdout'], 'api.v1.greeting.show')
        && str_contains($text['stdout'], 'name:slug'),
    'Text route listing must include names and constraints.',
);

$invalidArguments = runRoutesCommand(
    ['--unsupported'],
    $environment,
);

routesCommandCheck(
    $invalidArguments['status'] === 2
        && $invalidArguments['stdout'] === ''
        && str_contains($invalidArguments['stderr'], 'Usage:'),
    'Invalid arguments must produce usage on stderr and exit 2.',
);

$invalidEnvironment = $environment;
$invalidEnvironment['DATABASE_PORT'] = '0';

// Help must remain available without bootstrapping the application.
$help = runRoutesCommand(['--help'], $invalidEnvironment);

routesCommandCheck(
    $help['status'] === 0
        && $help['stderr'] === ''
        && str_contains($help['stdout'], 'Usage:'),
    'Help must not depend on valid application configuration.',
);

$failed = runRoutesCommand(['--json'], $invalidEnvironment);

routesCommandCheck(
    $failed['status'] === 1
        && $failed['stdout'] === ''
        && str_starts_with($failed['stderr'], 'FAIL: '),
    'Bootstrap failure must not produce partial JSON output.',
);

echo 'PASS: Route listing, JSON metadata, usage handling, and configuration failure.'
    . PHP_EOL;

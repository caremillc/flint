<?php

declare(strict_types=1);

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 *
 * @return array{status: int, stdout: string, stderr: string}
 */
function runRouteUrlCommand(
    array $arguments,
    array $environment,
): array {
    $root = dirname(__DIR__);

    $process = proc_open(
        [
            PHP_BINARY,
            $root . '/bin/route-url.php',
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

    if (! is_resource($process)) {
        throw new RuntimeException(
            'Could not start the route URL command.',
        );
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
        throw new RuntimeException(
            'Could not read route URL command output.',
        );
    }

    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function routeUrlCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @var array<string, string> $environment */
$environment = getenv();

$environment['APP_NAME'] = 'Route URL command test';
$environment['APP_DEBUG'] = 'false';
$environment['DATABASE_PORT'] = '3306';

$successCases = [
    [
        'literal route',
        ['health'],
        '/health',
    ],
    [
        'parameter encoding',
        ['greeting', '{"name":"Ada Lovelace"}'],
        '/hello/Ada%20Lovelace',
    ],
    [
        'slash encoding',
        ['greeting', '{"name":"a/b"}'],
        '/hello/a%2Fb',
    ],
    [
        'constrained group route',
        [
            'api.v1.greeting.show',
            '{"name":"Ada-Lovelace"}',
            '{"style":"formal"}',
        ],
        '/api/v1/hello/Ada-Lovelace?style=formal',
    ],
    [
        'query encoding',
        [
            'health',
            '{}',
            '{"source":"command line","marker":"a&b"}',
        ],
        '/health?source=command%20line&marker=a%26b',
    ],
];

foreach ($successCases as [$label, $arguments, $expected]) {
    $result = runRouteUrlCommand($arguments, $environment);

    routeUrlCheck(
        $result['status'] === 0
            && $result['stdout'] === $expected . PHP_EOL
            && $result['stderr'] === '',
        $label . ': unexpected command result.',
    );
}

$failureCases = [
    'unknown route' => ['missing.route'],
    'missing parameter' => ['greeting'],
    'extra parameter' => ['health', '{"extra":"value"}'],
    'numeric parameter' => ['greeting', '{"name":123}'],
    'constraint mismatch' => [
        'api.v1.greeting.show',
        '{"name":"Ada_Lovelace"}',
    ],
    'invalid JSON' => ['greeting', '{'],
    'array parameters' => ['greeting', '[]'],
    'array query' => ['health', '{}', '[]'],
    'nested query value' => [
        'health',
        '{}',
        '{"filter":{"name":"Ada"}}',
    ],
];

foreach ($failureCases as $label => $arguments) {
    $result = runRouteUrlCommand($arguments, $environment);

    routeUrlCheck(
        $result['status'] === 1
            && $result['stdout'] === ''
            && str_starts_with($result['stderr'], 'FAIL: '),
        $label . ': expected a clean command failure.',
    );
}

$result = runRouteUrlCommand([], $environment);

routeUrlCheck(
    $result['status'] === 2
        && $result['stdout'] === ''
        && str_starts_with($result['stderr'], 'Usage:'),
    'Missing arguments must produce a usage error.',
);

$invalidEnvironment = $environment;
$invalidEnvironment['DATABASE_PORT'] = '0';

$result = runRouteUrlCommand(['--help'], $invalidEnvironment);

routeUrlCheck(
    $result['status'] === 0
        && str_starts_with($result['stdout'], 'Usage:')
        && $result['stderr'] === '',
    'Help must work without application bootstrap.',
);

$result = runRouteUrlCommand(['health'], $invalidEnvironment);

routeUrlCheck(
    $result['status'] === 1
        && $result['stdout'] === ''
        && str_starts_with($result['stderr'], 'FAIL: '),
    'Invalid configuration must fail without writing a URL.',
);

$result = runRouteUrlCommand(['health'], $environment);

routeUrlCheck(
    $result['status'] === 0
        && $result['stdout'] === '/health' . PHP_EOL
        && $result['stderr'] === '',
    'A valid invocation must succeed after failed invocations.',
);

echo 'PASS: Route URL generation, encoding, constraints, CLI errors, and recovery.'
    . PHP_EOL;
    
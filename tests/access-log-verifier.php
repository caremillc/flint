<?php

declare(strict_types=1);

/**
 * @param list<array<string, mixed>> $records
 *
 * @return array{status: int, stdout: string, stderr: string}
 */
function runAccessLogVerifier(
    string $directory,
    array $records,
    string $manifest,
): array {
    $root = dirname(__DIR__);
    $log = '';

    foreach ($records as $record) {
        $log .= '[test-server] '
            . json_encode($record, JSON_THROW_ON_ERROR)
            . PHP_EOL;
    }

    $logPath = $directory . '/server.log';
    $manifestPath = $directory . '/expected.tsv';
    $stdoutPath = $directory . '/stdout.txt';
    $stderrPath = $directory . '/stderr.txt';

    if (
        file_put_contents($logPath, $log) === false
        || file_put_contents($manifestPath, $manifest) === false
    ) {
        throw new RuntimeException(
            'Could not write verifier test fixtures.',
        );
    }

    $process = proc_open(
        [
            PHP_BINARY,
            $root . '/bin/verify-access-log.php',
            $logPath,
            $manifestPath,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ],
        $pipes,
        $root,
    );

    if (! is_resource($process)) {
        throw new RuntimeException(
            'Could not start the access-log verifier.',
        );
    }

    fclose($pipes[0]);
    $status = proc_close($process);

    $stdout = file_get_contents($stdoutPath);
    $stderr = file_get_contents($stderrPath);

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException(
            'Could not read verifier test output.',
        );
    }

    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function verifierCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir()
    . '/flint-log-verifier-'
    . bin2hex(random_bytes(12));

if (! mkdir($directory, 0700)) {
    throw new RuntimeException(
        'Could not create the verifier test directory.',
    );
}

try {
    $id = str_repeat('a', 32);

    $valid = [
        'event' => 'http.request_completed',
        'request_id' => $id,
        'method' => 'GET',
        'route_name' => 'health',
        'status' => 200,
        'duration_ms' => 0.25,
    ];

    $namedManifest = "GET\t200\t{$id}\thealth\n";

    $result = runAccessLogVerifier(
        $directory,
        [$valid],
        $namedManifest,
    );

    verifierCheck(
        $result['status'] === 0
            && $result['stderr'] === ''
            && $result['stdout']
                === 'PASS: 1 HTTP responses matched unique access-log records and expected route names.'
                    . PHP_EOL,
        'A matching named-route record must pass.',
    );

    $unmatched = array_replace($valid, [
        'route_name' => null,
        'status' => 404,
    ]);

    $result = runAccessLogVerifier(
        $directory,
        [$unmatched],
        "GET\t404\t{$id}\t-\n",
    );

    verifierCheck(
        $result['status'] === 0 && $result['stderr'] === '',
        'An explicitly expected null route name must pass.',
    );

    // Readiness probes may exist without an expected-response entry.
    $probe = array_replace($valid, [
        'request_id' => str_repeat('b', 32),
    ]);

    $result = runAccessLogVerifier(
        $directory,
        [$probe, $valid],
        $namedManifest,
    );

    verifierCheck(
        $result['status'] === 0 && $result['stderr'] === '',
        'An unrelated readiness record must not break correlation.',
    );

    $missingRouteName = $valid;
    unset($missingRouteName['route_name']);

    $failureCases = [
        [
            'wrong route name',
            [array_replace($valid, ['route_name' => 'home'])],
            $namedManifest,
            'expected route health, received home',
        ],
        [
            'null instead of a named route',
            [array_replace($valid, ['route_name' => null])],
            $namedManifest,
            'expected route health, received null',
        ],
        [
            'named route instead of null',
            [$valid],
            "GET\t200\t{$id}\t-\n",
            'expected route null, received health',
        ],
        [
            'missing route field',
            [$missingRouteName],
            $namedManifest,
            'missing or unexpected fields',
        ],
        [
            'invalid route type',
            [array_replace($valid, ['route_name' => 42])],
            $namedManifest,
            'route names must be valid names or null',
        ],
        [
            'duplicate completion records',
            [$valid, $valid],
            $namedManifest,
            'exactly one completion record; found 2',
        ],
        [
            'missing completion record',
            [],
            $namedManifest,
            'exactly one completion record; found 0',
        ],
        [
            'reused manifest request ID',
            [$valid],
            $namedManifest . $namedManifest,
            'Different requests reused a request ID',
        ],
        [
            'wrong HTTP status',
            [array_replace($valid, ['status' => 500])],
            $namedManifest,
            'metadata differs from the HTTP result',
        ],
        [
            'negative duration',
            [array_replace($valid, ['duration_ms' => -1])],
            $namedManifest,
            'duration must be finite and non-negative',
        ],
        [
            'unexpected request content',
            [array_replace($valid, ['body' => 'private-data'])],
            $namedManifest,
            'missing or unexpected fields',
        ],
        [
            'old three-column manifest',
            [$valid],
            "GET\t200\t{$id}\n",
            'must contain method, status, ID, and route name',
        ],
        [
            'empty manifest',
            [$valid],
            '',
            'No expected requests were recorded',
        ],
    ];

    foreach ($failureCases as [
        $label,
        $records,
        $manifest,
        $expectedError,
    ]) {
        $result = runAccessLogVerifier(
            $directory,
            $records,
            $manifest,
        );

        verifierCheck(
            $result['status'] === 1
                && $result['stdout'] === ''
                && str_starts_with($result['stderr'], 'FAIL: ')
                && str_contains($result['stderr'], $expectedError),
            $label . ': verifier did not reject the fixture as expected.'
                . PHP_EOL
                . $result['stderr'],
        );
    }

    // Confirm a failed invocation leaves no state affecting the next run.
    $result = runAccessLogVerifier(
        $directory,
        [$valid],
        $namedManifest,
    );

    verifierCheck(
        $result['status'] === 0 && $result['stderr'] === '',
        'A valid invocation must succeed after rejected fixtures.',
    );

    echo 'PASS: Access-log verifier acceptance, 13 rejection cases, and recovery.'
        . PHP_EOL;
} finally {
    foreach ([
        'server.log',
        'expected.tsv',
        'stdout.txt',
        'stderr.txt',
    ] as $filename) {
        $path = $directory . '/' . $filename;

        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
}

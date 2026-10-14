<?php

declare(strict_types=1);

try {
    if ($argc !== 3) {
        throw new RuntimeException(
            'Usage: php bin/verify-access-log.php server-log expected-requests',
        );
    }

    $logLines = file($argv[1], FILE_IGNORE_NEW_LINES);
    $expectedLines = file($argv[2], FILE_IGNORE_NEW_LINES);

    if ($logLines === false || $expectedLines === false) {
        throw new RuntimeException(
            'Could not read access-log verification files.',
        );
    }

    if ($expectedLines === []) {
        throw new RuntimeException(
            'No expected requests were recorded.',
        );
    }

    $recordsById = [];

    foreach ($logLines as $line) {
        // PHP may prefix error_log output with a timestamp.
        $start = strpos($line, '{');

        if ($start === false) {
            continue;
        }

        $record = json_decode(substr($line, $start), true);

        if (
            ! is_array($record)
            || ($record['event'] ?? null) !== 'http.request_completed'
        ) {
            continue;
        }

        $id = $record['request_id'] ?? null;

        if (! is_string($id)) {
            continue;
        }

        $recordsById['id:' . $id][] = $record;
    }

    $seen = [];

    foreach ($expectedLines as $line) {
        $parts = explode("\t", $line);

        if (count($parts) !== 4) {
            throw new RuntimeException(
                'An expected-request entry must contain method, status, ID, and route name.',
            );
        }

        [$method, $status, $id, $expectedRoute] = $parts;

        if (
            $method === ''
            || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1
            || preg_match('/\A[2-5][0-9]{2}\z/', $status) !== 1
        ) {
            throw new RuntimeException(
                'An expected request has an invalid method, ID, or status.',
            );
        }

        if (
            $expectedRoute !== '-'
            && preg_match(
                '/\A[A-Za-z_][A-Za-z0-9_.-]*\z/',
                $expectedRoute,
            ) !== 1
        ) {
            throw new RuntimeException(
                'An expected request has an invalid route name.',
            );
        }

        $expectedRouteName = $expectedRoute === '-'
            ? null
            : $expectedRoute;

        $key = 'id:' . $id;

        if (isset($seen[$key])) {
            throw new RuntimeException(
                'Different requests reused a request ID.',
            );
        }

        $seen[$key] = true;
        $matches = $recordsById[$key] ?? [];

        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Request %s must have exactly one completion record; found %d.',
                $id,
                count($matches),
            ));
        }

        $record = $matches[0];

        $keys = array_keys($record);
        sort($keys);

        $expectedKeys = [
            'duration_ms',
            'event',
            'method',
            'request_id',
            'route_name',
            'status',
        ];

        if ($keys !== $expectedKeys) {
            throw new RuntimeException(
                'The access record contains missing or unexpected fields.',
            );
        }

        if (
            $record['method'] !== $method
            || $record['status'] !== (int) $status
        ) {
            throw new RuntimeException(
                'Access-log metadata differs from the HTTP result.',
            );
        }

        $routeName = $record['route_name'];

        if (
            $routeName !== null
            && (
                ! is_string($routeName)
                || preg_match(
                    '/\A[A-Za-z_][A-Za-z0-9_.-]*\z/',
                    $routeName,
                ) !== 1
            )
        ) {
            throw new RuntimeException(
                'Access-log route names must be valid names or null.',
            );
        }

        if ($routeName !== $expectedRouteName) {
            throw new RuntimeException(sprintf(
                'Request %s: expected route %s, received %s.',
                $id,
                $expectedRouteName ?? 'null',
                $routeName ?? 'null',
            ));
        }

        $duration = $record['duration_ms'];

        if (
            (! is_int($duration) && ! is_float($duration))
            || ! is_finite((float) $duration)
            || $duration < 0
        ) {
            throw new RuntimeException(
                'Access-log duration must be finite and non-negative.',
            );
        }
    }

    echo sprintf(
        'PASS: %d HTTP responses matched unique access-log records and expected route names.',
        count($expectedLines),
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);

    exit(1);
}

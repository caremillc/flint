<?php

declare(strict_types=1);

try {
    if ($argc !== 5) {
        throw new RuntimeException(
            'Usage: php bin/http-head-check.php address path status content-length',
        );
    }

    [, $address, $path, $expectedStatus, $expectedLength] = $argv;

    if (preg_match('/\A127\.0\.0\.1:([0-9]{1,5})\z/', $address, $matches) !== 1) {
        throw new RuntimeException('The address must use 127.0.0.1 and a port.');
    }

    $port = (int) $matches[1];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('The port is invalid.');
    }

    if (
        ! str_starts_with($path, '/')
        || preg_match('/[\x00-\x20\x7F]/', $path) === 1
    ) {
        throw new RuntimeException('The request path is invalid.');
    }

    if (
        preg_match('/\A[2-5][0-9]{2}\z/', $expectedStatus) !== 1
        || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $expectedLength) !== 1
    ) {
        throw new RuntimeException('The expected status or length is invalid.');
    }

    $socket = @stream_socket_client(
        'tcp://' . $address,
        $errorCode,
        $errorMessage,
        2.0,
    );

    if ($socket === false) {
        throw new RuntimeException('Could not connect to the application server.');
    }

    try {
        stream_set_timeout($socket, 5);

        $request = sprintf(
            "HEAD %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n",
            $path,
            $address,
        );

        $offset = 0;
        $requestLength = strlen($request);

        while ($offset < $requestLength) {
            $written = fwrite($socket, substr($request, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the HEAD request.');
            }

            $offset += $written;
        }

        $raw = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);

        if ($raw === false || $metadata['timed_out']) {
            throw new RuntimeException('Could not read the complete HEAD response.');
        }
    } finally {
        fclose($socket);
    }

    $parts = explode("\r\n\r\n", $raw, 2);

    if (count($parts) !== 2) {
        throw new RuntimeException('The response has no header terminator.');
    }

    [$headerBlock, $body] = $parts;

    if ($body !== '') {
        throw new RuntimeException(sprintf(
            'HEAD returned %d forbidden body bytes.',
            strlen($body),
        ));
    }

    $lines = explode("\r\n", $headerBlock);
    $statusLine = array_shift($lines);

    if (
        preg_match(
            '/\AHTTP\/1\.[01] ([0-9]{3})(?: |$)/',
            $statusLine,
            $matches,
        ) !== 1
    ) {
        throw new RuntimeException('The response status line is invalid.');
    }

    if ($matches[1] !== $expectedStatus) {
        throw new RuntimeException(sprintf(
            'Expected status %s, received %s.',
            $expectedStatus,
            $matches[1],
        ));
    }

    $headers = [];

    foreach ($lines as $line) {
        $separator = strpos($line, ':');

        if ($separator === false) {
            throw new RuntimeException('The response contains an invalid header.');
        }

        $name = strtolower(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1), " \t");

        $headers[$name][] = $value;
    }

    if (($headers['content-length'] ?? []) !== [$expectedLength]) {
        throw new RuntimeException('Unexpected HEAD Content-Length.');
    }

    if (isset($headers['transfer-encoding'])) {
        throw new RuntimeException('Unexpected HEAD Transfer-Encoding.');
    }

    echo sprintf(
        'PASS: Raw HEAD %s -> %s, Content-Length %s, zero body bytes.',
        $path,
        $expectedStatus,
        $expectedLength,
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);

    exit(1);
}

<?php

declare(strict_types=1);

use Flint\Configuration\Exception\ConfigurationException;
use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Http\RequestHandler;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function jsonLimitCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function loadJsonLimitApplication(): RequestHandler
{
    ob_start();

    try {
        $handler = require dirname(__DIR__) . '/bootstrap/http.php';
    } finally {
        $output = ob_get_clean();
    }

    jsonLimitCheck(
        $output === '',
        'Application bootstrap must remain silent.',
    );

    if (! $handler instanceof RequestHandler) {
        throw new RuntimeException(
            'HTTP bootstrap must return a request handler.',
        );
    }

    return $handler;
}

$names = [
    'APP_NAME',
    'APP_DEBUG',
    'DATABASE_PORT',
    'JSON_MAX_BODY_BYTES',
];

$originalEnvironment = [];

foreach ($names as $name) {
    $originalEnvironment[$name] = getenv($name);
}

try {
    putenv('APP_NAME=JSON limit test');
    putenv('APP_DEBUG=false');
    putenv('DATABASE_PORT=3306');

    // Construct both applications before resolving their lazy handlers.
    putenv('JSON_MAX_BODY_BYTES=16');
    $small = loadJsonLimitApplication();

    putenv('JSON_MAX_BODY_BYTES=8192');
    $large = loadJsonLimitApplication();

    $body = '{"name":"Ada"}' . str_repeat(' ', 32);

    $request = new Request(
        method: 'POST',
        target: '/hello',
        headers: (new Headers())->with(
            'Content-Type',
            'application/json',
        ),
        body: $body,
    );

    $smallResponse = $small->handle($request);
    $largeResponse = $large->handle($request);

    jsonLimitCheck(
        $smallResponse->status() === 413,
        'The small application must reject the body.',
    );

    jsonLimitCheck(
        json_decode(
            $smallResponse->body(),
            true,
            64,
            JSON_THROW_ON_ERROR,
        ) === [
            'type' => 'about:blank',
            'title' => 'Content Too Large',
            'status' => 413,
            'detail' => 'The JSON body must not exceed 16 bytes.',
        ],
        'The rejection must report the configured limit.',
    );

    jsonLimitCheck(
        $largeResponse->status() === 200
            && $largeResponse->body() === '{"greeting":"Hello, Ada"}',
        'The larger application must accept the same body.',
    );

    foreach (['0', '-1', '1048577', 'invalid', ''] as $value) {
        putenv('JSON_MAX_BODY_BYTES=' . $value);

        $rejected = false;

        try {
            loadJsonLimitApplication();
        } catch (ConfigurationException) {
            $rejected = true;
        }

        jsonLimitCheck(
            $rejected,
            'Invalid JSON limit must reject application startup.',
        );
    }

    // Previously constructed applications retain their configurations.
    jsonLimitCheck(
        $small->handle($request)->status() === 413
            && $large->handle($request)->status() === 200,
        'Environment changes must not alter existing applications.',
    );

    // Removing the override restores the default.
    putenv('JSON_MAX_BODY_BYTES');
    $default = loadJsonLimitApplication();

    $oversized = new Request(
        method: 'POST',
        target: '/hello',
        headers: (new Headers())->with(
            'Content-Type',
            'application/json',
        ),
        body: '{"name":"Ada"}' . str_repeat(' ', 4096),
    );

    $defaultResponse = $default->handle($oversized);

    jsonLimitCheck(
        $defaultResponse->status() === 413
            && str_contains(
                $defaultResponse->body(),
                '4096 bytes',
            ),
        'Removing the override must restore the default limit.',
    );

    jsonLimitCheck(
        $default->handle($request)->status() === 200,
        'Valid startup and requests must recover after rejected configuration.',
    );

    echo 'PASS: Configured JSON limits, lazy-handler isolation, startup rejection, defaults, and recovery.'
        . PHP_EOL;
} finally {
    foreach ($originalEnvironment as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}

<?php

declare(strict_types=1);

use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Http\RequestHandler;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function jsonGreetingBootstrapCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

ob_start();

try {
    $handler = require dirname(__DIR__) . '/bootstrap/http.php';
} finally {
    $startupOutput = ob_get_clean();
}

jsonGreetingBootstrapCheck(
    $startupOutput === '',
    'HTTP bootstrap must not produce output.',
);

jsonGreetingBootstrapCheck(
    $handler instanceof RequestHandler,
    'HTTP bootstrap must return a request handler.',
);

$badRequest = [
    'type' => 'about:blank',
    'title' => 'Bad Request',
    'status' => 400,
    'detail' => 'The body must contain a valid JSON object.',
];

$invalidFields = [
    'type' => 'about:blank',
    'title' => 'Unprocessable Content',
    'status' => 422,
    'invalid_fields' => ['style'],
];
$unsupportedMedia = [
    'type' => 'about:blank',
    'title' => 'Unsupported Media Type',
    'status' => 415,
    'detail' => 'Use application/json without Content-Encoding.',
];

$cases = [
    [
        'label' => 'successful greeting',
        'target' => '/hello',
        'contentType' => 'application/json',
        'body' => '{"name":"Ada"}',
        'status' => 200,
        'responseType' => 'application/json',
        'expected' => ['greeting' => 'Hello, Ada'],
    ],
    [
        'label' => 'malformed JSON',
        'target' => '/hello',
        'contentType' => 'application/json',
        'body' => '{"name":',
        'status' => 400,
        'responseType' => 'application/problem+json',
        'expected' => $badRequest,
    ],
    [
        'label' => 'non-object JSON',
        'target' => '/hello',
        'contentType' => 'application/json',
        'body' => '[]',
        'status' => 400,
        'responseType' => 'application/problem+json',
        'expected' => $badRequest,
    ],
    [
        'label' => 'unsupported greeting option',
        'target' => '/hello',
        'contentType' => 'application/json',
        'body' => '{"name":"Ada","style":"unknown"}',
        'status' => 422,
        'responseType' => 'application/problem+json',
        'expected' => $invalidFields,
    ],
    [
        'label' => 'unsupported media type',
        'target' => '/hello',
        'contentType' => 'text/plain',
        'body' => '{"name":"Ada"}',
        'status' => 415,
        'responseType' => 'application/problem+json',
        'expected' => $unsupportedMedia,
    ],
    [
        'label' => 'query rejection before JSON handling',
        'target' => '/hello?name=Ada&name=Grace',
        'contentType' => 'application/json',
        'body' => '{"name":',
        'status' => 400,
        'responseType' => 'text/plain; charset=utf-8',
        'expected' => 'Bad request',
    ],
    [
        'label' => 'recovery on the same handler',
        'target' => '/hello',
        'contentType' => 'application/json',
        'body' => '{"name":"Grace","style":"formal"}',
        'status' => 200,
        'responseType' => 'application/json',
        'expected' => ['greeting' => 'Welcome, Grace'],
    ],
];

$requestIds = [];

foreach ($cases as $case) {
    $request = new Request(
        'POST',
        $case['target'],
        (new Headers())
            ->with('Content-Type', $case['contentType'])
            ->with('X-Request-ID', 'untrusted-client-id'),
        $case['body'],
    );

    $response = $handler->handle($request);
    $label = $case['label'];

    jsonGreetingBootstrapCheck(
        $response->status() === $case['status'],
        $label . ': unexpected status.',
    );

    jsonGreetingBootstrapCheck(
        $response->headers()->get('Content-Type')
            === [$case['responseType']],
        $label . ': unexpected response media type.',
    );

    jsonGreetingBootstrapCheck(
        $response->headers()->get('X-Content-Type-Options')
            === ['nosniff'],
        $label . ': missing response policy header.',
    );

    if (is_array($case['expected'])) {
        $document = json_decode(
            $response->body(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        jsonGreetingBootstrapCheck(
            $document === $case['expected'],
            $label . ': unexpected JSON document.',
        );
    } else {
        jsonGreetingBootstrapCheck(
            $response->body() === $case['expected'],
            $label . ': unexpected response body.',
        );
    }

    $ids = $response->headers()->get('X-Request-ID');

    jsonGreetingBootstrapCheck(
        count($ids) === 1,
        $label . ': expected exactly one request ID.',
    );

    $requestId = $ids[0];

    jsonGreetingBootstrapCheck(
        preg_match('/\A[0-9a-f]{32}\z/', $requestId) === 1,
        $label . ': invalid generated request ID.',
    );

    jsonGreetingBootstrapCheck(
        !isset($requestIds[$requestId]),
        $label . ': request ID was reused.',
    );

    $requestIds[$requestId] = true;

    jsonGreetingBootstrapCheck(
        !$request->hasAttribute('request.id')
            && !$request->hasAttribute('request.query'),
        $label . ': middleware mutated the original request.',
    );

    jsonGreetingBootstrapCheck(
        $request->headers()->get('X-Request-ID')
            === ['untrusted-client-id'],
        $label . ': the original request headers changed.',
    );
}

echo 'PASS: JSON application routing, Problem Details, middleware headers, query precedence, and recovery.'
    . PHP_EOL;

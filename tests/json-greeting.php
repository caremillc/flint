<?php

declare(strict_types=1);

use App\Http\Handlers\JsonGreetingHandler;
use Flint\Http\Headers;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function jsonGreetingCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$handler = new JsonGreetingHandler();

$problems = [
    400 => [
        'type' => 'about:blank',
        'title' => 'Bad Request',
        'status' => 400,
        'detail' => 'The body must contain a valid JSON object.',
    ],
    415 => [
        'type' => 'about:blank',
        'title' => 'Unsupported Media Type',
        'status' => 415,
        'detail' => 'Use application/json without Content-Encoding.',
    ],
    422 => [
        'type' => 'about:blank',
        'title' => 'Unprocessable Content',
        'status' => 422,
    ],
];

$cases = [
    ['{"name":"Ada"}', 200, 'Hello, Ada'],
    ['{"name":"Ada","style":"formal"}', 200, 'Welcome, Ada'],
    ['', 400, null],
    ['{"name":', 400, null],
    ['[]', 400, null],
    ['{}', 422, null],
    ['{"name":42}', 422, null],
    ['{"name":"   "}', 422, null],
    ['{"name":"' . str_repeat('a', 101) . '"}', 422, null],
    ['{"name":"Ada","style":"unknown"}', 422, null],
    ['{"name":"Ada","style":null}', 422, null],
    ['{"name":"Ada","extra":true}', 422, null],
];

foreach ($cases as [$body, $status, $greeting]) {
    $response = $handler->handle(
        new Request(
            method: 'POST',
            target: '/hello',
            headers: new Headers()->with('Content-Type', 'application/json'),
            body: $body,
        ),
    );

    jsonGreetingCheck(
        $response->status() === $status,
        'Unexpected JSON greeting status.',
    );

    $expected = $status === 200
        ? ['greeting' => $greeting]
        : $problems[$status];

    jsonGreetingCheck(
        json_decode(
            $response->body(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ) === $expected,
        'Unexpected JSON response document.',
    );

    jsonGreetingCheck(
        $response->headers()->get('Content-Type') === [
            $status === 200
                ? 'application/json'
                : 'application/problem+json',
        ],
        'Unexpected response media type.',
    );
}

$unsupportedHeaders = [
    new Headers(),
    new Headers()->with('Content-Type', 'text/plain'),
    new Headers()->with(
        'Content-Type',
        'application/json',
        'application/json',
    ),
    new Headers()
        ->with('Content-Type', 'application/json')
        ->with('Content-Encoding', 'gzip'),
];

foreach ($unsupportedHeaders as $headers) {
    $response = $handler->handle(
        new Request(
            method: 'POST',
            target: '/hello',
            headers: $headers,
            body: '{"name":"Ada"}',
        ),
    );

    jsonGreetingCheck($response->status() === 415, 'Expected status 415.');

    jsonGreetingCheck(
        $response->headers()->get('Content-Type')
            === ['application/problem+json'],
        'Unsupported media errors must use Problem Details.',
    );

    jsonGreetingCheck(
        json_decode(
            $response->body(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ) === $problems[415],
        'Unexpected unsupported-media problem document.',
    );
}

$withCharset = $handler->handle(
    new Request(
        method: 'POST',
        target: '/hello',
        headers: new Headers()->with(
            'Content-Type',
            'Application/JSON; charset=utf-8',
        ),
        body: '{"name":"Ada"}',
    ),
);

jsonGreetingCheck(
    $withCharset->status() === 200
        && $withCharset->body() === '{"greeting":"Hello, Ada"}',
    'JSON media-type parameters must preserve successful decoding.',
);

echo 'PASS: JSON greeting, Problem Details, media types, and field validation.'
    . PHP_EOL;

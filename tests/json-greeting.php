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

$cases = [
    ['{"name":"Ada"}', 200, '{"greeting":"Hello, Ada"}'],
    [
        '{"name":"Ada","style":"formal"}',
        200,
        '{"greeting":"Welcome, Ada"}',
    ],
    ['', 400, 'Bad request'],
    ['{"name":', 400, 'Bad request'],
    ['[]', 400, 'Bad request'],
    ['{}', 422, 'Unprocessable content'],
    ['{"name":42}', 422, 'Unprocessable content'],
    ['{"name":"   "}', 422, 'Unprocessable content'],
    ['{"name":"' . str_repeat('a', 101) . '"}', 422, 'Unprocessable content'],
    ['{"name":"Ada","style":"unknown"}', 422, 'Unprocessable content'],
    ['{"name":"Ada","style":null}', 422, 'Unprocessable content'],
    ['{"name":"Ada","extra":true}', 422, 'Unprocessable content'],
];

foreach ($cases as [$body, $status, $expectedBody]) {
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
    jsonGreetingCheck(
        $response->body() === $expectedBody,
        'Unexpected JSON greeting response.',
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

    jsonGreetingCheck(
        $response->status() === 415
            && $response->body() === 'Unsupported media type',
        'Unsupported representations must receive 415.',
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
    $withCharset->status() === 200,
    'The JSON media type must accept case differences and parameters.',
);

echo 'PASS: JSON greeting, media types, decoding, and field validation.'
    . PHP_EOL;
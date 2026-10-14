<?php

declare(strict_types=1);

use App\Http\Handlers\JsonGreetingHandler;
use Flint\Http\Headers;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function jsonGreetingCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$handler = new JsonGreetingHandler();

$badRequest = [
    'type' => 'about:blank',
    'title' => 'Bad Request',
    'status' => 400,
    'detail' => 'The body must contain a valid JSON object.',
];

$unsupportedMedia = [
    'type' => 'about:blank',
    'title' => 'Unsupported Media Type',
    'status' => 415,
    'detail' => 'Use application/json without Content-Encoding.',
];

$successCases = [
    ['{"name":"Ada"}', 'Hello, Ada'],
    ['{"name":"Ada","style":"formal"}', 'Welcome, Ada'],
];

foreach ($successCases as [$body, $greeting]) {
    $response = $handler->handle(new Request(
        'POST',
        '/hello',
        (new Headers())->with('Content-Type', 'application/json'),
        $body,
    ));

    jsonGreetingCheck(
        $response->status() === 200,
        'Valid greeting: unexpected status.',
    );

    jsonGreetingCheck(
        $response->headers()->get('Content-Type')
            === ['application/json'],
        'Valid greeting: unexpected media type.',
    );

    jsonGreetingCheck(
        json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)
            === ['greeting' => $greeting],
        'Valid greeting: unexpected document.',
    );
}

foreach (['', '{"name":', '[]'] as $body) {
    $response = $handler->handle(new Request(
        'POST',
        '/hello',
        (new Headers())->with('Content-Type', 'application/json'),
        $body,
    ));

    jsonGreetingCheck(
        $response->status() === 400,
        'Invalid JSON object: expected 400.',
    );

    jsonGreetingCheck(
        $response->headers()->get('Content-Type')
            === ['application/problem+json'],
        'Invalid JSON object: unexpected media type.',
    );

    jsonGreetingCheck(
        json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)
            === $badRequest,
        'Invalid JSON object: unexpected problem document.',
    );
}

$invalidFieldCases = [
    ['{}', ['name']],
    ['{"name":null}', ['name']],
    ['{"name":42}', ['name']],
    ['{"name":"   "}', ['name']],
    [
        json_encode(
            ['name' => str_repeat('a', 101)],
            JSON_THROW_ON_ERROR,
        ),
        ['name'],
    ],
    ['{"name":"Ada","style":"unknown"}', ['style']],
    ['{"name":"Ada","style":null}', ['style']],
    ['{"name":"Ada","extra":true}', ['extra']],
    [
        '{"name":42,"style":false,"extra":"private-value"}',
        ['name', 'style', 'extra'],
    ],
];

foreach ($invalidFieldCases as [$body, $invalidFields]) {
    $response = $handler->handle(new Request(
        'POST',
        '/hello',
        (new Headers())->with('Content-Type', 'application/json'),
        $body,
    ));

    jsonGreetingCheck(
        $response->status() === 422,
        'Invalid fields: expected 422.',
    );

    jsonGreetingCheck(
        $response->headers()->get('Content-Type')
            === ['application/problem+json'],
        'Invalid fields: unexpected media type.',
    );

    jsonGreetingCheck(
        json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)
            === [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'invalid_fields' => $invalidFields,
            ],
        'Invalid fields: unexpected problem document.',
    );
}

$unsupportedHeaders = [
    new Headers(),
    (new Headers())->with('Content-Type', 'text/plain'),
    (new Headers())->with(
        'Content-Type',
        'application/json',
        'application/json',
    ),
    (new Headers())
        ->with('Content-Type', 'application/json')
        ->with('Content-Encoding', 'gzip'),
];

foreach ($unsupportedHeaders as $headers) {
    $response = $handler->handle(new Request(
        'POST',
        '/hello',
        $headers,
        '{"name":"Ada"}',
    ));

    jsonGreetingCheck(
        $response->status() === 415,
        'Unsupported media: expected 415.',
    );

    jsonGreetingCheck(
        $response->headers()->get('Content-Type')
            === ['application/problem+json'],
        'Unsupported media: unexpected media type.',
    );

    jsonGreetingCheck(
        json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)
            === $unsupportedMedia,
        'Unsupported media: unexpected problem document.',
    );
}

$response = $handler->handle(new Request(
    'POST',
    '/hello',
    (new Headers())->with(
        'Content-Type',
        'Application/JSON; charset=utf-8',
    ),
    '{"name":"Ada"}',
));

jsonGreetingCheck(
    $response->status() === 200
        && $response->body() === '{"greeting":"Hello, Ada"}',
    'A valid request must succeed after earlier rejections.',
);

echo 'PASS: JSON greeting, invalid field reporting, media types, and recovery.'
    . PHP_EOL;

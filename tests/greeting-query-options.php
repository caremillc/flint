<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function greetingOptionsCheck(
    bool $condition,
    string $message,
): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$handler = new GreetingHandler();

$cases = [
    [
        'default',
        [],
        200,
        '{"greeting":"Hello, Ada"}',
    ],
    [
        'explicit one',
        ['repeat' => '1'],
        200,
        '{"greeting":"Hello, Ada"}',
    ],
    [
        'two greetings',
        ['repeat' => '2'],
        200,
        '{"greeting":"Hello, Ada Hello, Ada"}',
    ],
    [
        'formal three',
        ['style' => 'formal', 'repeat' => '3'],
        200,
        '{"greeting":"Welcome, Ada Welcome, Ada Welcome, Ada"}',
    ],
    [
        'zero',
        ['repeat' => '0'],
        400,
        'Bad request',
    ],
    [
        'negative',
        ['repeat' => '-1'],
        400,
        'Bad request',
    ],
    [
        'above maximum',
        ['repeat' => '4'],
        400,
        'Bad request',
    ],
    [
        'leading zero',
        ['repeat' => '02'],
        400,
        'Bad request',
    ],
    [
        'empty',
        ['repeat' => ''],
        400,
        'Bad request',
    ],
    [
        'overflow',
        ['repeat' => '999999999999999999999999999999'],
        400,
        'Bad request',
    ],
    [
        'unsupported style',
        ['style' => 'unknown', 'repeat' => '2'],
        400,
        'Bad request',
    ],
    [
        'recovery',
        ['repeat' => '2'],
        200,
        '{"greeting":"Hello, Ada Hello, Ada"}',
    ],
];

foreach ($cases as [$label, $query, $status, $body]) {
    $request = new Request(
        target: '/hello/Ada',
        attributes: [
            'route.parameters' => ['name' => 'Ada'],
            'request.query' => $query,
        ],
    );

    $response = $handler->handle($request);

    greetingOptionsCheck(
        $response->status() === $status
            && $response->body() === $body,
        $label . ': unexpected greeting response.',
    );

    greetingOptionsCheck(
        $request->attribute('request.query') === $query,
        $label . ': the request query context changed.',
    );
}

// An integer in decoded context indicates a programming error.
// Real decoded query values must be strings.
$invalidContext = new Request(
    target: '/hello/Ada',
    attributes: [
        'route.parameters' => ['name' => 'Ada'],
        'request.query' => ['repeat' => 2],
    ],
);

$rejected = false;

try {
    $handler->handle($invalidContext);
} catch (LogicException) {
    $rejected = true;
}

greetingOptionsCheck(
    $rejected,
    'Malformed internal query context must raise a programming error.',
);

echo 'PASS: Typed greeting query options, bounds, context integrity, and recovery.'
    . PHP_EOL;

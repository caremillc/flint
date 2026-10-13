<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function greetingCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$handler = new GreetingHandler();

$cases = [
    [
        [],
        200,
        '{"greeting":"Hello, Ada"}',
    ],
    [
        ['style' => 'friendly'],
        200,
        '{"greeting":"Hello, Ada"}',
    ],
    [
        ['style' => 'formal'],
        200,
        '{"greeting":"Welcome, Ada"}',
    ],
    [
        ['style' => 'unknown'],
        400,
        'Bad request',
    ],
    [
        ['style' => ''],
        400,
        'Bad request',
    ],
    [
        ['style' => 'FORMAL'],
        400,
        'Bad request',
    ],
    [
        ['source' => 'test'],
        200,
        '{"greeting":"Hello, Ada"}',
    ],
];

foreach ($cases as [$query, $status, $body]) {
    $request = new Request(
        target: '/hello/Ada',
        attributes: [
            'route.parameters' => ['name' => 'Ada'],
            'request.query' => $query,
        ],
    );

    $response = $handler->handle($request);

    greetingCheck(
        $response->status() === $status,
        'Unexpected greeting status.',
    );
    greetingCheck(
        $response->body() === $body,
        'Unexpected greeting body.',
    );
    greetingCheck(
        $response->headers()->get('Content-Type') === [
            $status === 200
                ? 'application/json'
                : 'text/plain; charset=utf-8',
        ],
        'Unexpected greeting content type.',
    );
    greetingCheck(
        $request->attribute('request.query') === $query,
        'The handler must not modify request query context.',
    );
}

$invalidContexts = [
    [],
    ['route.parameters' => ['name' => 'Ada']],
    [
        'route.parameters' => ['name' => 'Ada'],
        'request.query' => ['style' => null],
    ],
];

foreach ($invalidContexts as $attributes) {
    $rejected = false;

    try {
        $handler->handle(new Request(attributes: $attributes));
    } catch (LogicException) {
        $rejected = true;
    }

    greetingCheck(
        $rejected,
        'Invalid internal context must raise a programming error.',
    );
}

echo 'PASS: Greeting options, client rejection, and internal context validation.'
    . PHP_EOL;
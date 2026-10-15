<?php

declare(strict_types=1);

use App\Http\Handlers\GreetingHandler;
use Flint\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function greetingQueryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$handler = new GreetingHandler();

$cases = [
    ['default greeting', [], 200, 'Hello, Ada'],
    ['repeat twice', ['repeat' => '2'], 200, 'Hello, Ada Hello, Ada'],
    [
        'formal repeated greeting',
        ['style' => 'formal', 'repeat' => '3'],
        200,
        'Welcome, Ada Welcome, Ada Welcome, Ada',
    ],
    ['explicit one', ['repeat' => '1'], 200, 'Hello, Ada'],
    ['leading zero', ['repeat' => '02'], 400, null],
    ['zero', ['repeat' => '0'], 400, null],
    ['negative', ['repeat' => '-1'], 400, null],
    ['above maximum', ['repeat' => '4'], 400, null],
    ['empty', ['repeat' => ''], 400, null],
    ['decimal', ['repeat' => '2.0'], 400, null],
    ['plus sign', ['repeat' => '+2'], 400, null],
    ['whitespace', ['repeat' => ' 2'], 400, null],
    [
        'integer overflow',
        ['repeat' => (string) PHP_INT_MAX . '0'],
        400,
        null,
    ],
    ['unsupported style', ['style' => 'unknown'], 400, null],
    ['recovery', ['style' => 'formal'], 200, 'Welcome, Ada'],
];

foreach ($cases as [$label, $query, $expectedStatus, $expectedGreeting]) {
    $request = (new Request('GET', '/hello/Ada'))
        ->withAttribute('route.parameters', ['name' => 'Ada'])
        ->withAttribute('request.query', $query);

    $response = $handler->handle($request);

    greetingQueryCheck(
        $response->status() === $expectedStatus,
        $label . ': unexpected status.',
    );

    if ($expectedStatus === 200) {
        greetingQueryCheck(
            $response->headers()->get('Content-Type')
                === ['application/json'],
            $label . ': unexpected content type.',
        );

        $document = json_decode(
            $response->body(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        greetingQueryCheck(
            $document === ['greeting' => $expectedGreeting],
            $label . ': unexpected greeting.',
        );
    } else {
        greetingQueryCheck(
            $response->headers()->get('Content-Type')
                === ['text/plain; charset=utf-8'],
            $label . ': unexpected rejection content type.',
        );

        greetingQueryCheck(
            $response->body() === 'Bad request',
            $label . ': unexpected rejection body.',
        );
    }

    greetingQueryCheck(
        $request->attribute('request.query') === $query,
        $label . ': original query attributes changed.',
    );
}

echo sprintf(
    'PASS: Greeting query options, strict integers, bounds, and recovery (%d cases).',
    count($cases),
) . PHP_EOL;

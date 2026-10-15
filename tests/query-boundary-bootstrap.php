<?php

declare(strict_types=1);

use Flint\Http\Request;
use Flint\Http\RequestHandler;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function queryBoundaryCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

ob_start();

try {
    $handler = require dirname(__DIR__) . '/bootstrap/http.php';
} finally {
    $startupOutput = ob_get_clean();
}

queryBoundaryCheck(
    $startupOutput === '',
    'HTTP bootstrap must remain silent.',
);

queryBoundaryCheck(
    $handler instanceof RequestHandler,
    'HTTP bootstrap must return a request handler.',
);

$cases = [
    [
        'numeric zero name',
        '/hello/Ada?0=value',
        400,
        null,
    ],
    [
        'encoded numeric name',
        '/hello/Ada?%30=value',
        400,
        null,
    ],
    [
        'negative numeric name',
        '/hello/Ada?-1=value',
        400,
        null,
    ],
    [
        'positive numeric name',
        '/hello/Ada?42=value',
        400,
        null,
    ],
    [
        'encoded duplicate',
        '/hello/Ada?repeat=2&%72epeat=3',
        400,
        null,
    ],
    [
        'literal bracket name',
        '/hello/Ada?repeat%5B%5D=2',
        200,
        'Hello, Ada',
    ],
    [
        'literal bracket and ordinary name',
        '/hello/Ada?repeat%5B%5D=3&repeat=2',
        200,
        'Hello, Ada Hello, Ada',
    ],
    [
        'recovery',
        '/hello/Ada?style=formal&repeat=2',
        200,
        'Welcome, Ada Welcome, Ada',
    ],
];

$requestIds = [];

foreach ($cases as [$label, $target, $expectedStatus, $greeting]) {
    $request = new Request(
        target: $target,
        attributes: [
            'request.query' => ['stale' => 'value'],
        ],
    );

    $response = $handler->handle($request);

    queryBoundaryCheck(
        $response->status() === $expectedStatus,
        $label . ': unexpected status.',
    );

    queryBoundaryCheck(
        $response->headers()->get('X-Content-Type-Options')
            === ['nosniff'],
        $label . ': missing security header.',
    );

    $ids = $response->headers()->get('X-Request-ID');

    queryBoundaryCheck(
        count($ids) === 1,
        $label . ': expected one request ID.',
    );

    $requestId = $ids[0];

    queryBoundaryCheck(
        preg_match('/\A[0-9a-f]{32}\z/', $requestId) === 1,
        $label . ': invalid request ID.',
    );

    queryBoundaryCheck(
        ! isset($requestIds[$requestId]),
        $label . ': request ID was reused.',
    );

    $requestIds[$requestId] = true;

    if ($expectedStatus === 400) {
        queryBoundaryCheck(
            $response->headers()->get('Content-Type')
                === ['text/plain; charset=utf-8'],
            $label . ': unexpected rejection content type.',
        );

        queryBoundaryCheck(
            $response->body() === 'Bad request',
            $label . ': unexpected rejection body.',
        );
    } else {
        queryBoundaryCheck(
            $response->headers()->get('Content-Type')
                === ['application/json'],
            $label . ': unexpected success content type.',
        );

        $document = json_decode(
            $response->body(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        queryBoundaryCheck(
            $document === ['greeting' => $greeting],
            $label . ': unexpected greeting.',
        );
    }

    queryBoundaryCheck(
        $request->attribute('request.query') === ['stale' => 'value'],
        $label . ': original query attributes changed.',
    );

    queryBoundaryCheck(
        ! $request->hasAttribute('request.id')
            && ! $request->hasAttribute('route.name'),
        $label . ': pipeline mutated the original request.',
    );
}

echo 'PASS: Application query boundaries, literal brackets, '
    . 'response headers, request isolation, and recovery (8 cases).'
    . PHP_EOL;
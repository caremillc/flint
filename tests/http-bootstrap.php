<?php

declare(strict_types=1);

use Flint\Http\Request;
use Flint\Http\RequestHandler;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function httpBootstrapCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

ob_start();

try {
    $first = require dirname(__DIR__) . '/bootstrap/http.php';
    $second = require dirname(__DIR__) . '/bootstrap/http.php';
} finally {
    $output = ob_get_clean();
}

httpBootstrapCheck(
    $output === '',
    'HTTP bootstrap must not produce output.',
);

if (
    ! $first instanceof RequestHandler
    || ! $second instanceof RequestHandler
) {
    throw new RuntimeException('HTTP bootstrap must return a request handler.');
}

httpBootstrapCheck(
    $first !== $second,
    'Each bootstrap must construct an independent handler.',
);

$health = $first->handle(new Request(method: 'GET', target: '/health'));

httpBootstrapCheck($health->status() === 200, 'Health status must be 200.');
httpBootstrapCheck(
    $health->body() === '{"status":"ok"}',
    'Health response body is incorrect.',
);
httpBootstrapCheck(
    $health->headers()->get('Content-Type') === ['application/json'],
    'Health response must use JSON.',
);

$head = $first->handle(new Request(method: 'HEAD', target: '/health'));

httpBootstrapCheck($head->status() === 200, 'HEAD fallback must succeed.');
httpBootstrapCheck(
    $head->body() === $health->body(),
    'HEAD must retain the representation until emission.',
);

$missing = $first->handle(new Request(method: 'GET', target: '/missing'));

httpBootstrapCheck($missing->status() === 404, 'Unknown routes must return 404.');

$disallowed = $first->handle(new Request(method: 'POST', target: '/health'));

httpBootstrapCheck(
    $disallowed->status() === 405,
    'Unsupported route methods must return 405.',
);
httpBootstrapCheck(
    $disallowed->headers()->get('Allow') === ['GET, HEAD'],
    'The Allow header is incorrect.',
);

$home = $first->handle(new Request(method: 'GET', target: '/'));

httpBootstrapCheck($home->status() === 200, 'Home status must be 200.');
httpBootstrapCheck(
    trim($home->body()) !== '',
    'Home must contain the configured application name.',
);

foreach ([$health, $head, $missing, $disallowed, $home] as $response) {
    httpBootstrapCheck(
        $response->headers()->get('X-Content-Type-Options') === ['nosniff'],
        'Application middleware must decorate successful and routing error responses.',
    );
}

$secondHealth = $second->handle(
    new Request(method: 'GET', target: '/health'),
);

httpBootstrapCheck(
    $secondHealth->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'An independently bootstrapped handler must include middleware.',
);

$repeatedHealth = $first->handle(
    new Request(method: 'GET', target: '/health'),
);

httpBootstrapCheck(
    $repeatedHealth->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'Repeated requests must receive exactly one middleware header value.',
);

$greeting = $first->handle(
    new Request(target: '/hello/Ada?source=test'),
);

httpBootstrapCheck(
    $greeting->status() === 200,
    'The parameterized greeting route must succeed.',
);
httpBootstrapCheck(
    $greeting->body() === '{"greeting":"Hello, Ada"}',
    'The handler must receive the captured name without the query string.',
);
httpBootstrapCheck(
    $greeting->headers()->get('Content-Type') === ['application/json'],
    'The greeting response must use JSON.',
);
httpBootstrapCheck(
    $greeting->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'Parameterized routes must retain response middleware.',
);

$greetingIds = $greeting->headers()->get('X-Request-ID');

httpBootstrapCheck(
    count($greetingIds) === 1
        && preg_match('/\A[0-9a-f]{32}\z/', $greetingIds[0]) === 1,
    'Parameterized routes must retain request-ID middleware.',
);

$encodedGreeting = $first->handle(
    new Request(target: '/hello/a%2Fb'),
);

httpBootstrapCheck(
    $encodedGreeting->body() === '{"greeting":"Hello, a%2Fb"}',
    'Route parameters must retain their percent encoding.',
);

$missingName = $first->handle(
    new Request(target: '/hello/'),
);

httpBootstrapCheck(
    $missingName->status() === 404,
    'An empty name must not match the route.',
);

echo 'PASS: HTTP bootstrap, silent startup, independent handlers, and routes.'
    . PHP_EOL;
    
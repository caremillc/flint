<?php

declare(strict_types=1);

use Flint\Http\Headers;
use Flint\Http\Output\PhpResponseOutput;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '';

if (!is_string($method) || !is_string($uri)) {
    throw new RuntimeException('Invalid HTTP fixture request.');
}

$path = parse_url($uri, PHP_URL_PATH);

if (!is_string($path)) {
    throw new RuntimeException('Invalid HTTP fixture path.');
}

$token = getenv('FLINT_HTTP_SMOKE_TOKEN');

if ($token === false || $token === '') {
    throw new RuntimeException('The HTTP fixture requires its test runner.');
}

if (!in_array($method, ['GET', 'HEAD'], true)) {
    $response = new Response(
        status: 405,
        headers: new Headers()->with('Allow', 'GET, HEAD'),
    );
} else {
    $response = match ($path) {
        '/ready' => ResponseFactory::text($token),
        '/text' => ResponseFactory::text("Caf\xC3\xA9"),
        '/json' => ResponseFactory::json(['ready' => true]),
        '/zero' => ResponseFactory::text('0'),
        '/cookies' => new Response(
            headers: new Headers()->with(
                'Set-Cookie',
                'first=1; Path=/',
                'second=2; Path=/',
            ),
        ),
        '/location' => new Response(
            status: 202,
            body: 'Accepted',
            headers: new Headers()->with('Location', '/next'),
        ),
        '/empty' => new Response(
            status: 204,
            headers: new Headers()->with('Content-Length', '999'),
        ),
        '/reset' => new Response(status: 205),
        '/cached' => new Response(
            status: 304,
            headers: new Headers()
                ->with('ETag', '"example"')
                ->with('Content-Length', '999'),
        ),
        default => ResponseFactory::text('Not found', status: 404),
    };
}

// The emitter should clear this previously queued header.
header('X-Stale: remove-me');

new ResponseEmitter(new PhpResponseOutput())->emit(
    $response,
    headRequest: $method === 'HEAD',
);

<?php

declare(strict_types=1);

use Flint\Http\Exception\RequestBodyTooLargeException;
use Flint\Http\Headers;
use Flint\Http\Output\PhpResponseOutput;
use Flint\Http\PhpRequestFactory;
use Flint\Http\RequestBodyReader;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$token = getenv('FLINT_HTTP_SMOKE_TOKEN');

if ($token === false || $token === '') {
    throw new RuntimeException('The HTTP fixture requires its test runner.');
}

$headRequest = ($_SERVER['REQUEST_METHOD'] ?? null) === 'HEAD';

try {
    $request = new PhpRequestFactory(
        bodyReader: new RequestBodyReader(maxBytes: 8),
    )->capture();

    $method = $request->method();
    $path = $request->path();

    if ($path === '/echo') {
        $response = $method === 'POST'
            ? new Response(
                body: $request->body(),
                headers: new Headers()
                    ->with('Content-Type', 'application/octet-stream')
                    ->with('X-Captured-Method', $request->method())
                    ->with(
                        'X-Captured-Content-Type',
                        $request->headers()->get('Content-Type')[0] ?? '',
                    ),
            )
            : new Response(
                status: 405,
                headers: new Headers()->with('Allow', 'POST'),
            );
    } elseif (!in_array($method, ['GET', 'HEAD'], true)) {
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
} catch (RequestBodyTooLargeException) {
    $response = ResponseFactory::text('Too large', status: 413);
} catch (InvalidArgumentException) {
    $response = ResponseFactory::text('Bad request', status: 400);
}

// Verify that emission clears previously queued PHP headers.
header('X-Stale: remove-me');

new ResponseEmitter(new PhpResponseOutput())->emit(
    $response,
    headRequest: $headRequest,
);

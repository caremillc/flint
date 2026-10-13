<?php

declare(strict_types=1);

use App\Http\ResponsePolicy;
use Flint\Http\Headers;
use Flint\Http\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function responsePolicyCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

foreach ([200, 400, 413, 500] as $status) {
    $headers = new Headers()
        ->with('Content-Type', 'text/plain; charset=utf-8')
        ->with('X-Request-ID', str_repeat('a', 32))
        ->with('Set-Cookie', 'first=1; Path=/', 'second=2; Path=/')
        ->with('X-Content-Type-Options', 'incorrect', 'duplicate');

    $original = new Response(
        status: $status,
        body: "Body\0bytes",
        headers: $headers,
    );

    $updated = ResponsePolicy::apply($original);
    $repeated = ResponsePolicy::apply($updated);

    responsePolicyCheck(
        $updated->status() === $status,
        'The policy must preserve response status.',
    );
    responsePolicyCheck(
        $updated->body() === $original->body(),
        'The policy must preserve body bytes.',
    );
    responsePolicyCheck(
        $updated->headers()->get('X-Content-Type-Options') === ['nosniff'],
        'The policy must replace existing values with one nosniff value.',
    );
    responsePolicyCheck(
        $updated->headers()->get('Content-Type')
            === $headers->get('Content-Type'),
        'The policy must preserve Content-Type.',
    );
    responsePolicyCheck(
        $updated->headers()->get('X-Request-ID')
            === $headers->get('X-Request-ID'),
        'The policy must preserve the request ID.',
    );
    responsePolicyCheck(
        $updated->headers()->get('Set-Cookie')
            === ['first=1; Path=/', 'second=2; Path=/'],
        'The policy must preserve separate cookie values.',
    );
    responsePolicyCheck(
        $original->headers()->get('X-Content-Type-Options')
            === ['incorrect', 'duplicate'],
        'The policy must not mutate the original response.',
    );
    responsePolicyCheck(
        $repeated->headers()->all() === $updated->headers()->all(),
        'Applying the policy twice must not change the resulting headers.',
    );
}

echo 'PASS: Response policy, header preservation, and repeated application.'
    . PHP_EOL;
    
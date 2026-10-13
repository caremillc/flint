<?php

declare(strict_types=1);

namespace App\Http;

use Flint\Http\Response;

final class ResponsePolicy
{
    public static function apply(Response $response): Response
    {
        return $response->withHeaders(
            $response->headers()->with(
                'X-Content-Type-Options',
                'nosniff',
            ),
        );
    }
}

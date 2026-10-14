<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

final class NoStoreMiddleware implements Middleware
{
    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        $response = $next->handle($request);

        return $response->withHeaders(
            $response->headers()->with('Cache-Control', 'no-store'),
        );
    }
}

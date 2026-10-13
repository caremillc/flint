<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ResponsePolicy;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;

final class ResponseHeadersMiddleware implements Middleware
{
    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        return ResponsePolicy::apply(
            $next->handle($request),
        );
    }
}
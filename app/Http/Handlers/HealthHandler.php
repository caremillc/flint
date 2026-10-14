<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;

final class HealthHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        return ResponseFactory::json([
            'status' => 'ok',
        ]);
    }
}

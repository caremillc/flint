<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteUrlGenerator;

final readonly class LinksHandler implements RequestHandler
{
    public function __construct(
        private RouteUrlGenerator $urls,
    ) {
    }

    public function handle(Request $request): Response
    {
        return ResponseFactory::json([
            'links' => [
                'self' => $this->urls->path('links'),
                'health' => $this->urls->path('health'),
                'greeting' => $this->urls->target(
                    'greeting',
                    ['name' => 'Ada Lovelace'],
                    ['style' => 'formal'],
                ),
                'api_health' => $this->urls->path(
                    'api.v1.health',
                ),
                'api_greeting' => $this->urls->target(
                    'api.v1.greeting.show',
                    ['name' => 'Ada-Lovelace'],
                    ['style' => 'formal'],
                ),
            ],
        ]);
    }
}

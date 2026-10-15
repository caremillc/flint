<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\LocalRedirectResponseFactory;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Routing\RouteUrlGenerator;

final readonly class HealthRedirectHandler implements RequestHandler
{
    public function __construct(
        private RouteUrlGenerator $urls,
    ) {
    }

    public function handle(Request $request): Response
    {
        return LocalRedirectResponseFactory::create(
            $this->urls->path('health'),
        );
    }
}

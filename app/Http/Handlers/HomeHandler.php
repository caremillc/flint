<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Configuration\Configuration;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;

final readonly class HomeHandler implements RequestHandler
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    public function handle(Request $request): Response
    {
        return ResponseFactory::text(
            $this->configuration->string('app.name'),
        );
    }
}

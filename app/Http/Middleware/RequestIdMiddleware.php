<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use RuntimeException;

final readonly class RequestIdMiddleware implements Middleware
{
    /**
     * @param (Closure(): string)|null $generate
     */
    public function __construct(
        private ?Closure $generate = null,
    ) {}

    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        $id = $this->generate === null
            ? bin2hex(random_bytes(16))
            : ($this->generate)();

        if (preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1) {
            throw new RuntimeException('The request ID generator returned an invalid ID.');
        }

        $response = $next->handle(
            $request->withAttribute('request.id', $id),
        );

        return $response->withHeaders(
            $response->headers()->with('X-Request-ID', $id),
        );
    }
}

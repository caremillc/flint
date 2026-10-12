<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Throwable;

final readonly class ExceptionMiddleware implements Middleware
{
    /**
     * @param Closure(Throwable): void $report
     */
    public function __construct(
        private Closure $report,
    ) {}

    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        try {
            return $next->handle($request);
        } catch (Throwable $exception) {
            ($this->report)($exception);

            return ResponseFactory::text('Internal server error', 500);
        }
    }
}

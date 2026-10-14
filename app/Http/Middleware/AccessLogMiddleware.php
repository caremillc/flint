<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Routing\RouteLogContext;

final readonly class AccessLogMiddleware implements Middleware
{
    /**
     * @param Closure(string): void $write
     * @param (Closure(): float)|null $clock
     */
    public function __construct(
        private Closure $write,
        private ?Closure $clock = null,
    ) {
    }

    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        $started = $this->now();

        // Always create fresh context, replacing any incoming attribute.
        $context = new RouteLogContext();

        $routedRequest = $request
            ->withoutAttribute('route.name')
            ->withAttribute('route.log_context', $context);

        $response = $next->handle($routedRequest);

        $duration = round(
            max(0.0, ($this->now() - $started) * 1000),
            3,
        );

        $id = $request->attribute('request.id');

        if (
            ! is_string($id)
            || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1
        ) {
            $id = null;
        }

        $record = json_encode(
            [
                'event' => 'http.request_completed',
                'request_id' => $id,
                'method' => $request->method(),
                'route_name' => $context->routeName(),
                'status' => $response->status(),
                'duration_ms' => $duration,
            ],
            JSON_THROW_ON_ERROR,
        );

        ($this->write)($record);

        return $response;
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return hrtime(true) / 1_000_000_000;
    }
}

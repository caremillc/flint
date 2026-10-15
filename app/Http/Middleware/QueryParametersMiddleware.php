<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Flint\Http\Middleware;
use Flint\Http\QueryParameters;
use Flint\Http\QueryStringDecoder;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use InvalidArgumentException;

final class QueryParametersMiddleware implements Middleware
{
    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        try {
            $parameters = QueryStringDecoder::decode(
                $request->queryString(),
            );

            // Validate the map before exposing it to application handlers.
            new QueryParameters($parameters);
        } catch (InvalidArgumentException) {
            return ResponseFactory::text('Bad request', 400);
        }

        // Keep downstream exceptions outside the query-validation catch.
        return $next->handle(
            $request->withAttribute('request.query', $parameters),
        );
    }
}

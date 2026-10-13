<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use LogicException;

final class GreetingHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        $parameters = $request->attribute('route.parameters');

        if (
            ! is_array($parameters)
            || ! isset($parameters['name'])
            || ! is_string($parameters['name'])
            || $parameters['name'] === ''
        ) {
            throw new LogicException(
                'GreetingHandler requires the name route parameter.',
            );
        }

        $query = $request->attribute('request.query');

        if (! is_array($query)) {
            throw new LogicException(
                'GreetingHandler requires decoded query parameters.',
            );
        }

        $style = array_key_exists('style', $query)
            ? $query['style']
            : 'friendly';

        if (! is_string($style)) {
            throw new LogicException(
                'Decoded query values must be strings.',
            );
        }

        $prefix = match ($style) {
            'friendly' => 'Hello',
            'formal' => 'Welcome',
            default => null,
        };

        if ($prefix === null) {
            return ResponseFactory::text('Bad request', 400);
        }

        return ResponseFactory::json([
            'greeting' => $prefix . ', ' . $parameters['name'],
        ]);
    }
}

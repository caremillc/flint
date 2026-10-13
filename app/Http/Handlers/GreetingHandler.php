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

        return ResponseFactory::json([
            'greeting' => 'Hello, ' . $parameters['name'],
        ]);
    }
}
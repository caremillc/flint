<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Greeting\GreetingStyle;
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
            !is_array($parameters)
            || !is_string($parameters['name'] ?? null)
            || $parameters['name'] === ''
        ) {
            throw new LogicException(
                'The greeting route requires a non-empty name parameter.',
            );
        }

        $query = $request->attribute('request.query');

        if (!is_array($query)) {
            throw new LogicException(
                'The greeting handler requires parsed query parameters.',
            );
        }

        $styleValue = array_key_exists('style', $query)
            ? $query['style']
            : GreetingStyle::Friendly->value;

        if (!is_string($styleValue)) {
            throw new LogicException(
                'Parsed greeting style must be a string.',
            );
        }

        $style = GreetingStyle::tryFrom($styleValue);

        if ($style === null) {
            return ResponseFactory::text('Bad request', 400);
        }

        return ResponseFactory::json([
            'greeting' => $style->greet($parameters['name']),
        ]);
    }
}

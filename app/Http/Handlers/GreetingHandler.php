<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Greeting\GreetingStyle;
use Flint\Http\QueryParameters;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteParameters;
use InvalidArgumentException;
use LogicException;

final class GreetingHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        $name = RouteParameters::fromRequest($request)->get('name');

        $values = $request->attribute('request.query');

        if (! is_array($values)) {
            throw new LogicException(
                'Greeting handling requires decoded query parameters.',
            );
        }

        try {
            $query = new QueryParameters($values);
        } catch (InvalidArgumentException $exception) {
            throw new LogicException(
                'Decoded query parameters contain invalid internal values.',
                previous: $exception,
            );
        }

        $styleValue = $query->string('style')
            ?? GreetingStyle::Friendly->value;

        $style = GreetingStyle::tryFrom($styleValue);

        if ($style === null) {
            return ResponseFactory::text('Bad request', 400);
        }

        try {
            $repeat = $query->integer('repeat') ?? 1;
        } catch (InvalidArgumentException) {
            return ResponseFactory::text('Bad request', 400);
        }

        if ($repeat < 1 || $repeat > 3) {
            return ResponseFactory::text('Bad request', 400);
        }

        return ResponseFactory::json([
            'greeting' => implode(
                ' ',
                array_fill(0, $repeat, $style->greet($name)),
            ),
        ]);
    }
}

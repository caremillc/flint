<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\Exception\InvalidJsonException;
use Flint\Http\JsonObjectDecoder;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;

final class JsonGreetingHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        $contentTypes = $request->headers()->get('Content-Type');

        if (
            count($contentTypes) !== 1
            || $request->headers()->has('Content-Encoding')
        ) {
            return ResponseFactory::text('Unsupported media type', 415);
        }

        $mediaType = strtolower(trim(
            explode(';', $contentTypes[0], 2)[0],
        ));

        if ($mediaType !== 'application/json') {
            return ResponseFactory::text('Unsupported media type', 415);
        }

        try {
            $payload = JsonObjectDecoder::decode($request->body());
        } catch (InvalidJsonException) {
            return ResponseFactory::text('Bad request', 400);
        }

        $fields = get_object_vars($payload);

        if (array_diff(array_keys($fields), ['name', 'style']) !== []) {
            return ResponseFactory::text('Unprocessable content', 422);
        }

        $name = $fields['name'] ?? null;

        if (
            ! is_string($name)
            || trim($name) === ''
            || strlen($name) > 100
        ) {
            return ResponseFactory::text('Unprocessable content', 422);
        }

        $style = array_key_exists('style', $fields)
            ? $fields['style']
            : 'friendly';

        if (! is_string($style)) {
            return ResponseFactory::text('Unprocessable content', 422);
        }

        $prefix = match ($style) {
            'friendly' => 'Hello',
            'formal' => 'Welcome',
            default => null,
        };

        if ($prefix === null) {
            return ResponseFactory::text('Unprocessable content', 422);
        }

        return ResponseFactory::json([
            'greeting' => $prefix . ', ' . $name,
        ]);
    }
}

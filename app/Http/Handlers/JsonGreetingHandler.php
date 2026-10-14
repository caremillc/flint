<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use Flint\Http\Exception\InvalidJsonException;
use Flint\Http\JsonObjectDecoder;
use Flint\Http\ProblemResponseFactory;
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
            return self::unsupportedMediaType();
        }

        $mediaType = strtolower(trim(
            explode(';', $contentTypes[0], 2)[0],
        ));

        if ($mediaType !== 'application/json') {
            return self::unsupportedMediaType();
        }

        try {
            $payload = JsonObjectDecoder::decode($request->body());
        } catch (InvalidJsonException) {
            return ProblemResponseFactory::create(
                400,
                'Bad Request',
                'The body must contain a valid JSON object.',
            );
        }

        $fields = get_object_vars($payload);

        if (array_diff(array_keys($fields), ['name', 'style']) !== []) {
            return self::invalidFields();
        }

        $name = $fields['name'] ?? null;

        if (
            ! is_string($name)
            || trim($name) === ''
            || strlen($name) > 100
        ) {
            return self::invalidFields();
        }

        $style = array_key_exists('style', $fields)
            ? $fields['style']
            : 'friendly';

        if (! is_string($style)) {
            return self::invalidFields();
        }

        $prefix = match ($style) {
            'friendly' => 'Hello',
            'formal' => 'Welcome',
            default => null,
        };

        if ($prefix === null) {
            return self::invalidFields();
        }

        return ResponseFactory::json([
            'greeting' => $prefix . ', ' . $name,
        ]);
    }

    private static function unsupportedMediaType(): Response
    {
        return ProblemResponseFactory::create(
            415,
            'Unsupported Media Type',
            'Use application/json without Content-Encoding.',
        );
    }

    private static function invalidFields(): Response
    {
        return ProblemResponseFactory::create(
            422,
            'Unprocessable Content',
        );
    }
}

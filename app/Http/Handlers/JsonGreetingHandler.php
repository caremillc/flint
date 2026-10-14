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

        $mediaType = strtolower(
            trim(explode(';', $contentTypes[0], 2)[0]),
        );

        if ($mediaType !== 'application/json') {
            return self::unsupportedMediaType();
        }

        try {
            $document = JsonObjectDecoder::decode($request->body());
        } catch (InvalidJsonException) {
            return ProblemResponseFactory::create(
                400,
                'Bad Request',
                'The body must contain a valid JSON object.',
            );
        }

        $fields = get_object_vars($document);

        $name = $fields['name'] ?? null;

        $style = array_key_exists('style', $fields)
            ? $fields['style']
            : 'friendly';

        $validName = is_string($name)
            && trim($name) !== ''
            && strlen($name) <= 100;

        $validStyle = is_string($style)
            && in_array($style, ['friendly', 'formal'], true);

        $invalidFields = [];

        if (!$validName) {
            $invalidFields[] = 'name';
        }

        if (!$validStyle) {
            $invalidFields[] = 'style';
        }

        foreach ($fields as $field => $_value) {
            if ($field !== 'name' && $field !== 'style') {
                $invalidFields[] = (string) $field;
            }
        }

        if ($invalidFields !== []) {
            return ProblemResponseFactory::create(
                422,
                'Unprocessable Content',
                extensions: [
                    'invalid_fields' => $invalidFields,
                ],
            );
        }

        $prefix = $style === 'formal' ? 'Welcome' : 'Hello';

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
}

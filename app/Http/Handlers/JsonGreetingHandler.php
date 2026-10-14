<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Http\Input\InvalidGreetingInput;
use App\Http\Input\JsonGreetingInput;
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

        try {
            $input = JsonGreetingInput::fromObject($document);
        } catch (InvalidGreetingInput $exception) {
            return ProblemResponseFactory::create(
                422,
                'Unprocessable Content',
                extensions: [
                    'invalid_fields' => $exception->fields(),
                ],
            );
        }

        return ResponseFactory::json([
            'greeting' => $input->style->greet($input->name),
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

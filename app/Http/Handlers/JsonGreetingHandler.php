<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Http\Input\InvalidGreetingInput;
use App\Http\Input\JsonGreetingInput;
use Flint\Configuration\Configuration;
use Flint\Http\Exception\InvalidJsonException;
use Flint\Http\Exception\JsonBodyTooLargeException;
use Flint\Http\Exception\UnsupportedJsonMediaTypeException;
use Flint\Http\JsonRequestDecoder;
use Flint\Http\ProblemResponseFactory;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;

final readonly class JsonGreetingHandler implements RequestHandler
{
    private int $maxBodyBytes;

    public function __construct(Configuration $configuration)
    {
        $this->maxBodyBytes = $configuration->integer(
            'http.json.max_body_bytes',
        );
    }

    public function handle(Request $request): Response
    {
        try {
            $document = JsonRequestDecoder::decode(
                $request,
                bigIntegersAsStrings: false,
                maxBytes: $this->maxBodyBytes,
            );
        } catch (UnsupportedJsonMediaTypeException) {
            return ProblemResponseFactory::create(
                415,
                'Unsupported Media Type',
                'Use application/json without Content-Encoding.',
            );
        } catch (JsonBodyTooLargeException) {
            return ProblemResponseFactory::create(
                413,
                'Content Too Large',
                sprintf(
                    'The JSON body must not exceed %d bytes.',
                    $this->maxBodyBytes,
                ),
            );
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
}

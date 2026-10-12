<?php

declare(strict_types=1);

use Flint\Http\Exception\RequestBodyTooLargeException;
use Flint\Http\Output\PhpResponseOutput;
use Flint\Http\PhpRequestFactory;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$headRequest = ($_SERVER['REQUEST_METHOD'] ?? null) === 'HEAD';

$respond = static function (): Response {
    $handler = require dirname(__DIR__) . '/bootstrap/http.php';

    if (! $handler instanceof RequestHandler) {
        throw new RuntimeException('HTTP bootstrap returned an invalid handler.');
    }

    try {
        $request = new PhpRequestFactory()->capture();
    } catch (RequestBodyTooLargeException) {
        return ResponseFactory::text('Payload too large', 413);
    } catch (InvalidArgumentException) {
        return ResponseFactory::text('Bad request', 400);
    }

    return $handler->handle($request);
};

try {
    $response = $respond();
} catch (Throwable $exception) {
    error_log(sprintf(
        '[Flint] HTTP request failed: %s: %s',
        $exception::class,
        $exception->getMessage(),
    ));

    $response = ResponseFactory::text('Internal server error', 500);
}

$emitter = new ResponseEmitter(new PhpResponseOutput());

$emitter->emit(
    $response,
    headRequest: $headRequest,
);

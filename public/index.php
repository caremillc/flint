<?php

declare(strict_types=1);

use App\Http\HttpApplicationFactory;
use App\Http\HttpApplicationRunner;
use App\Logging\ExceptionReporter;
use Flint\Foundation\Application;
use Flint\Http\Exception\RequestBodyTooLargeException;
use Flint\Http\Output\PhpResponseOutput;
use Flint\Http\PhpRequestFactory;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$headRequest = ($_SERVER['REQUEST_METHOD'] ?? null) === 'HEAD';

$reporter = new ExceptionReporter(
    static function (string $record): void {
        error_log($record);
    },
);

$emitter = new ResponseEmitter(new PhpResponseOutput());

$runner = new HttpApplicationRunner(
    emit: static function (Response $response) use (
        $emitter,
        $headRequest,
    ): void {
        $emitter->emit($response, headRequest: $headRequest);
    },
    report: $reporter->reportUncaught(...),
);

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';

    if (!$application instanceof Application) {
        throw new RuntimeException(
            'Application bootstrap returned an invalid value.',
        );
    }
} catch (Throwable $exception) {
    $runner->reportBootstrapFailure($exception);

    return;
}

$runner->run(
    $application,
    static function (Application $running): Response {
        $handler = HttpApplicationFactory::handler($running);

        try {
            $request = new PhpRequestFactory()->capture();
        } catch (RequestBodyTooLargeException) {
            return ResponseFactory::text('Payload too large', 413);
        } catch (InvalidArgumentException) {
            return ResponseFactory::text('Bad request', 400);
        }

        return $handler->handle($request);
    },
);

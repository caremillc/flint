<?php

declare(strict_types=1);

use App\Http\Handlers\JsonGreetingHandler;
use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\NoStoreMiddleware;
use App\Http\Middleware\QueryParametersMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Container\Exception\ResolutionException;
use Flint\Http\Headers;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Routing\Router;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function containerMiddlewareCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registerRoutes = require dirname(__DIR__) . '/routes/web.php';

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Expected a route registration closure.');
}

$container = new Container();

$middlewareConstructions = 0;
$handlerConstructions = 0;
$failNext = false;
$failure = new RuntimeException('Private construction failure.');
$reported = [];

$container->bind(
    NoStoreMiddleware::class,
    static function (ContainerInterface $services) use (
        &$middlewareConstructions,
        &$failNext,
        $failure,
    ): NoStoreMiddleware {
        ++$middlewareConstructions;

        if ($failNext) {
            $failNext = false;

            throw $failure;
        }

        return new NoStoreMiddleware();
    },
);

$container->bind(
    JsonGreetingHandler::class,
    static function (ContainerInterface $services) use (
        &$handlerConstructions,
    ): JsonGreetingHandler {
        ++$handlerConstructions;

         return new JsonGreetingHandler(new Configuration(['http.json.max_body_bytes' => 4096,]));
        
    },
);

$router = new Router();

$registerRoutes(
    $router,
    Configuration::fromLayers([
        'app.name' => 'Container middleware application',
        'http.json.max_body_bytes' => 4096,
    ]),
    $container,
);

containerMiddlewareCheck(
    $middlewareConstructions === 0 && $handlerConstructions === 0,
    'Route registration must not construct middleware or handlers.',
);

// Exercise the production exception and response-policy middleware
// with a reporter that records exceptions for inspection.
$pipeline = new MiddlewarePipeline(
    $router,
    new ResponseHeadersMiddleware(),
    new ExceptionMiddleware(
        static function (
            Throwable $exception,
            Request $request,
        ) use (&$reported): void {
            $reported[] = $exception;
        },
    ),
    new QueryParametersMiddleware(),
);

$health = $pipeline->handle(new Request('GET', '/health'));

containerMiddlewareCheck(
    $health->status() === 200
        && $middlewareConstructions === 0,
    'The original health route must not resolve route middleware.',
);

$request = new Request(
    'POST',
    '/hello',
    (new Headers())->with('Content-Type', 'application/json'),
    '{"name":"Ada"}',
);

$greeting = $pipeline->handle($request);

containerMiddlewareCheck(
    $greeting->status() === 200
        && $greeting->headers()->get('Cache-Control') === ['no-store'],
    'The greeting route must execute its container middleware.',
);

containerMiddlewareCheck(
    $middlewareConstructions === 1 && $handlerConstructions === 1,
    'The selected middleware and handler must each resolve once.',
);

$apiHealth = $pipeline->handle(
    new Request('GET', '/api/v1/health'),
);

containerMiddlewareCheck(
    $apiHealth->status() === 200
        && $apiHealth->headers()->get('Cache-Control') === ['no-store'],
    'The API group must execute inherited container middleware.',
);

containerMiddlewareCheck(
    $middlewareConstructions === 2 && $handlerConstructions === 1,
    'Group middleware must preserve transient resolution.',
);

$queryRejection = $pipeline->handle(new Request(
    'POST',
    '/hello?name=Ada&name=Grace',
    (new Headers())->with('Content-Type', 'application/json'),
    '{"name":"Ada"}',
));

containerMiddlewareCheck(
    $queryRejection->status() === 400
        && $queryRejection->headers()->get('Cache-Control') === [],
    'Query rejection must occur before route middleware.',
);

$missing = $pipeline->handle(
    new Request('GET', '/api/v1/missing'),
);

$wrongMethod = $pipeline->handle(
    new Request('POST', '/api/v1/health'),
);

containerMiddlewareCheck(
    $missing->status() === 404 && $wrongMethod->status() === 405,
    'Routing rejection statuses must remain unchanged.',
);

containerMiddlewareCheck(
    $middlewareConstructions === 2 && $handlerConstructions === 1,
    'Rejected requests must not construct route services.',
);

// Fail construction on the next selected route.
$failNext = true;

$failed = $pipeline->handle($request);

containerMiddlewareCheck(
    $failed->status() === 500
        && $failed->body() === 'Internal server error',
    'Construction failures must become generic server errors.',
);

containerMiddlewareCheck(
    $failed->headers()->get('X-Content-Type-Options') === ['nosniff'],
    'The outer response policy must cover construction failures.',
);

containerMiddlewareCheck(
    $failed->headers()->get('Cache-Control') === [],
    'Middleware that failed construction must not apply its policy.',
);

containerMiddlewareCheck(
    $middlewareConstructions === 3 && $handlerConstructions === 1,
    'Middleware construction failure must prevent handler resolution.',
);

containerMiddlewareCheck(
    count($reported) === 1,
    'Construction failure must be reported exactly once.',
);

$reportedFailure = $reported[0] ?? null;

if (!$reportedFailure instanceof ResolutionException) {
    throw new RuntimeException(
        'The reporter must receive the container resolution exception.',
    );
}

containerMiddlewareCheck(
    $reportedFailure->getPrevious() === $failure,
    'The original construction exception must remain available.',
);

// Retry through the same container, router, pipeline, and request.
$recovered = $pipeline->handle($request);

containerMiddlewareCheck(
    $recovered->status() === 200
        && $recovered->body() === '{"greeting":"Hello, Ada"}',
    'The same pipeline must recover after construction failure.',
);

containerMiddlewareCheck(
    $recovered->headers()->get('Cache-Control') === ['no-store'],
    'Recovered middleware must apply its response policy.',
);

containerMiddlewareCheck(
    $middlewareConstructions === 4 && $handlerConstructions === 2,
    'Recovery must resolve fresh transient services.',
);

containerMiddlewareCheck(
    count($reported) === 1,
    'Successful recovery must not produce another exception report.',
);

containerMiddlewareCheck(
    !$request->hasAttribute('request.query'),
    'Pipeline execution must not mutate the original request.',
);

echo 'PASS: Lazy middleware resolution, routing boundaries, exception reporting, and recovery.'
    . PHP_EOL;
    
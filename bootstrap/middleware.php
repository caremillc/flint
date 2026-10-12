<?php

declare(strict_types=1);

use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use App\Logging\ExceptionReporter;

$reporter = new ExceptionReporter(
    static function (string $record): void {
        error_log($record);
    },
);

return [
    new RequestIdMiddleware(),
    new ResponseHeadersMiddleware(),
    new ExceptionMiddleware($reporter->__invoke(...)),
];

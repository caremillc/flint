<?php

declare(strict_types=1);

use App\Http\Middleware\AccessLogMiddleware;
use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use App\Logging\ExceptionReporter;

$writeLog = static function (string $record): void {
    error_log($record);
};

$reporter = new ExceptionReporter($writeLog);

return [
    new RequestIdMiddleware(),
    new ResponseHeadersMiddleware(),
    new AccessLogMiddleware($writeLog),
    new ExceptionMiddleware($reporter->__invoke(...)),
];

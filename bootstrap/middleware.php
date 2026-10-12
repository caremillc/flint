<?php

declare(strict_types=1);

use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;

return [
    new ResponseHeadersMiddleware(),

    new ExceptionMiddleware(
        static function (Throwable $exception): void {
            error_log(sprintf(
                '[Flint] Request handler failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));
        },
    ),
];
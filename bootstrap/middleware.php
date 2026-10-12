<?php

declare(strict_types=1);

use App\Http\Middleware\ExceptionMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\ResponseHeadersMiddleware;
use Flint\Http\Request;

return [
    new RequestIdMiddleware(),

    new ResponseHeadersMiddleware(),

    new ExceptionMiddleware(
        static function (Throwable $exception, Request $request): void {
            $id = $request->attribute('request.id');

            error_log(sprintf(
                '[Flint] request_id=%s handler_failed=%s message=%s',
                is_string($id) ? $id : 'unavailable',
                $exception::class,
                $exception->getMessage(),
            ));
        },
    ),
];
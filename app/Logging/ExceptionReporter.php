<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use Flint\Http\Request;
use Throwable;

final readonly class ExceptionReporter
{
    /**
     * @param Closure(string): void $write
     */
    public function __construct(
        private Closure $write,
    ) {}

    public function __invoke(Throwable $exception, Request $request): void
    {
        $id = $request->attribute('request.id');

        if (
            ! is_string($id)
            || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1
        ) {
            $id = null;
        }

        $record = json_encode(
            [
                'event' => 'http.handler_failed',
                'request_id' => $id,
                'method' => $request->method(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
            JSON_THROW_ON_ERROR
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_UNESCAPED_SLASHES,
        );

        ($this->write)($record);
    }
}

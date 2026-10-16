<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use Flint\Database\Exception\TransactionRollbackException;
use Flint\Foundation\ApplicationRunException;
use Flint\Http\Request;
use ReflectionClass;
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
        $this->writeRecord(
            'http.handler_failed',
            'HTTP request handling failed.',
            $exception,
            $request,
        );
    }

    public function reportUncaught(Throwable $exception): void
    {
        $this->writeRecord(
            'http.entrypoint_failed',
            'HTTP entry point failed.',
            $exception,
            null,
        );
    }

    private function writeRecord(
        string $event,
        string $message,
        Throwable $exception,
        ?Request $request,
    ): void {
        $id = $request?->attribute('request.id');

        if (
            !is_string($id)
            || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1
        ) {
            $id = null;
        }

        $record = [
            'event' => $event,
            'request_id' => $id,
            'method' => $request?->method(),
            'exception' => self::exceptionType($exception),
            'message' => $message,
        ];

        if ($exception instanceof ApplicationRunException) {
            $record['execution_exception'] = self::exceptionType(
                $exception->executionFailure,
            );

            $record['termination_exception'] = self::exceptionType(
                $exception->terminationFailure,
            );
        }

        if ($exception instanceof TransactionRollbackException) {
            $record['operation_exception'] = self::exceptionType(
                $exception->operationFailure,
            );

            $record['rollback_exception'] = self::exceptionType(
                $exception->rollbackFailure,
            );
        }
        ($this->write)(json_encode(
            $record,
            JSON_THROW_ON_ERROR
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_UNESCAPED_SLASHES,
        ));
    }

    private static function exceptionType(Throwable $exception): string
    {
        // Anonymous class names can contain source paths and line numbers.
        if ((new ReflectionClass($exception))->isAnonymous()) {
            return 'anonymous Throwable';
        }

        return $exception::class;
    }
}

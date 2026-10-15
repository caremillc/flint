<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use Flint\Foundation\Application;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Throwable;

final readonly class HttpApplicationRunner
{
    /**
     * @param Closure(Response): void $emit
     * @param Closure(Throwable): void $report
     */
    public function __construct(
        private Closure $emit,
        private Closure $report,
    ) {}

    /**
     * @param Closure(Application): Response $respond
     */
    public function run(
        Application $application,
        Closure $respond,
    ): void {
        $emissionStarted = false;

        try {
            $application->run(
                function (Application $running) use (
                    $respond,
                    &$emissionStarted,
                ): void {
                    try {
                        $response = $respond($running);
                    } catch (Throwable $exception) {
                        $this->reportSafely($exception);

                        $response = ResponseFactory::text(
                            'Internal server error',
                            500,
                        );
                    }

                    $response = ResponsePolicy::apply($response);

                    // An emitter may write partially before throwing.
                    $emissionStarted = true;

                    ($this->emit)($response);
                },
            );
        } catch (Throwable $exception) {
            $this->reportSafely($exception);

            if (!$emissionStarted) {
                $this->emitFailure();
            }
        }
    }

    public function reportBootstrapFailure(Throwable $exception): void
    {
        $this->reportSafely($exception);
        $this->emitFailure();
    }

    private function emitFailure(): void
    {
        try {
            $response = ResponsePolicy::apply(
                ResponseFactory::text('Internal server error', 500),
            );

            ($this->emit)($response);
        } catch (Throwable $exception) {
            // Never retry an emission that may have partially succeeded.
            $this->reportSafely($exception);
        }
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            ($this->report)($exception);
        } catch (Throwable) {
            error_log(
                '{"event":"http.reporting_failed",'
                . '"message":"Could not report an entry-point exception."}',
            );
        }
    }
}

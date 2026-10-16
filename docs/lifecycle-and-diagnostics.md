# Application lifecycle and diagnostics

## Lifecycle ownership

An application is constructed, providers are registered, and boot
callbacks run in registration order.

Successful repeated boot calls do nothing. Failed registration or boot
prevents further execution on that application.

Application::run() executes one operation and attempts termination.
Termination callbacks run in reverse registration order. Every callback
is attempted; the first cleanup failure is propagated.

Repeated termination does nothing. A terminated application cannot boot
or execute again.

Register cleanup when acquiring a resource. Registration is not
transactional: failure does not undo container changes or external effects.

## HTTP ownership

The production front controller owns one application.

It constructs the handler using HttpApplicationFactory, captures the
request, handles it, emits the response, and then terminates the application.

The reusable bootstrap/http.php handler does not terminate itself after
each request. Callers that need explicit resource ownership should retain
the application and construct its handler through HttpApplicationFactory.

Construct routes or an HTTP handler once per application. The handler
factory constructs its routes internally.

## Failures

A failure before response emission can produce a generic HTTP 500.

Once emission starts, the runner does not attempt another response.
An emitter may have partially written before throwing.

Cleanup failure after emission is reported. It cannot change the response
already sent.

ApplicationRunException retains both execution and termination failures
when both occur. Termination currently retains only the first failure
among multiple cleanup callbacks, although all callbacks are attempted.

Cleanup guarantees cover ordinary exception paths. Forced process
termination and fatal resource exhaustion are outside this guarantee.

Cleanup callbacks must not emit response output.

## Diagnostic records

Handler failures use http.handler_failed.
Entry-point failures use http.entrypoint_failed.

Records contain:
- Event name.
- Valid generated request ID, when available.
- Request method, when available.
- Named exception type or a fixed anonymous-exception label.
- A fixed diagnostic message.

Combined lifecycle failures also identify the execution and termination
exception types.

The reporter does not serialize exception messages, traces, source
locations, request targets, headers, bodies, or previous-exception messages.
Named exception classes are trusted application metadata.

Entry-point reports currently lack request correlation context.

If the configured writer fails, the HTTP runner attempts a fixed
http.reporting_failed fallback diagnostic. It does not retry recursively.

This policy applies regardless of APP_DEBUG. Detailed development
diagnostics are not implemented.

## Verification

Run:

    composer test:exception-reporting
    composer test:http-reporting-failure
    composer test:http-application-runner
    composer test:http-application-runner-boundaries
    composer test:http-failure
    composer check:all

5. Run the complete verification once.
(
    cd /c/xampp/htdocs/flint || exit 1

    composer dump-autoload &&
    composer validate --strict &&
    composer lint &&
    composer analyse:application &&

    composer test:exception-reporting &&
    composer test:http-reporting-failure &&
    composer test:http-application-runner &&
    composer test:http-application-runner-boundaries &&
    composer test:http-failure &&

    composer check:all
)

The existing failure smoke verifier requires string exception and message fields; this format retains them.
Progress: this increment addresses the review’s unrestricted exception-message logging finding and documents the lifecycle contract. It remains unverified until the checks run. The next larger milestone should close the remaining lifecycle regressions and clean-install gate, then move into the MySQL connection and transaction slice; another round of greeting features would not advance that goal.

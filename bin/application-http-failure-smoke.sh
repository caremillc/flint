#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

command -v php >/dev/null
command -v curl >/dev/null

JSON_MAX_BODY_BYTES=4096 \

env -u PHP_CLI_SERVER_WORKERS \
    APP_NAME="$application_name" \
    APP_DEBUG=false \
    DATABASE_PORT=0 \
    JSON_MAX_BODY_BYTES=4096 \

test -f public/index.php
test -f bin/http-head-check.php

temporary_dir="$(mktemp -d)"
server_pid=""

cleanup() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi

    rm -rf "$temporary_dir"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

fail() {
    echo "FAIL: $*" >&2

    if [ -f "$temporary_dir/server.log" ]; then
        cat "$temporary_dir/server.log" >&2
    fi

    exit 1
}

address="$(php -r '
$listener = stream_socket_server("tcp://127.0.0.1:0", $code, $message);

if ($listener === false) {
    fwrite(STDERR, "Could not reserve a test port.\n");
    exit(1);
}

$address = stream_socket_get_name($listener, false);
fclose($listener);

if ($address === false) {
    fwrite(STDERR, "Could not determine the test address.\n");
    exit(1);
}

echo $address;
')"

base_url="http://$address"

# Invalid configuration is intentional and applies only to this server.
env -u PHP_CLI_SERVER_WORKERS \
    APP_NAME='Flint Failure Smoke' \
    APP_DEBUG=false \
    DATABASE_PORT=0 \
    php \
        -d display_errors=0 \
        -d log_errors=1 \
        -d error_log= \
        -d output_buffering=0 \
        -d output_handler= \
        -d zlib.output_compression=0 \
        -S "$address" \
        -t public \
        public/index.php \
        >"$temporary_dir/server.log" 2>&1 &

server_pid=$!

ready=false

for ((attempt = 0; attempt < 50; attempt++)); do
    if ! kill -0 "$server_pid" 2>/dev/null; then
        fail "Application server stopped during startup."
    fi

    if status="$(curl --silent \
        --connect-timeout 1 \
        --max-time 2 \
        --output "$temporary_dir/probe" \
        --write-out '%{http_code}' \
        "$base_url/health")"; then
        if [ "$status" = 500 ]; then
            ready=true
            break
        fi

        fail "Invalid configuration returned HTTP $status instead of 500."
    fi

    sleep 0.1
done

if [ "$ready" != true ]; then
    fail "Application server did not become reachable."
fi

# Verify the generic GET error response.
status="$(curl --silent --show-error \
    --connect-timeout 2 \
    --max-time 5 \
    --dump-header "$temporary_dir/get-headers" \
    --output "$temporary_dir/get-body" \
    --write-out '%{http_code}' \
    "$base_url/health")" \
    || fail "GET request failed."

[ "$status" = 500 ] || fail "GET must return 500."

printf '%s' 'Internal server error' >"$temporary_dir/expected-body"

cmp -s "$temporary_dir/expected-body" "$temporary_dir/get-body" \
    || fail "GET exposed unexpected response content."

tr -d '\r' <"$temporary_dir/get-headers" \
    >"$temporary_dir/get-headers-normalized"

grep -Eiq '^content-type: text/plain; charset=utf-8$' \
    "$temporary_dir/get-headers-normalized" \
    || fail "GET returned an unexpected content type."

grep -Eiq '^content-length: 21$' \
    "$temporary_dir/get-headers-normalized" \
    || fail "GET returned an unexpected Content-Length."

grep -Eiq '^x-content-type-options: nosniff$' \
    "$temporary_dir/get-headers-normalized" \
    || fail "Bootstrap failure response is missing the nosniff header."

echo 'PASS: Invalid configuration, GET /health -> generic 500'

# Verify HEAD status, representation length, and zero body bytes.
MSYS2_ARG_CONV_EXCL='*' php bin/http-head-check.php \
    "$address" /health 500 21 \
    || fail "Raw HEAD bootstrap-failure check failed."

# Verify structured logs for readiness, GET, and HEAD requests.
php -r '
$lines = file($argv[1], FILE_IGNORE_NEW_LINES);

if ($lines === false) {
    fwrite(STDERR, "FAIL: Could not read the server log.\n");
    exit(1);
}

$matched = 0;

foreach ($lines as $line) {
    $start = strpos($line, "{");

    if ($start === false) {
        continue;
    }

    $record = json_decode(substr($line, $start), true);

    if (
        !is_array($record)
        || ($record["event"] ?? null) !== "http.entrypoint_failed"
    ) {
        continue;
    }

    if (
        !array_key_exists("request_id", $record)
        || $record["request_id"] !== null
        || !array_key_exists("method", $record)
        || $record["method"] !== null
        || !is_string($record["exception"] ?? null)
        || !is_string($record["message"] ?? null)
    ) {
        fwrite(STDERR, "FAIL: Malformed entry-point log record.\n");
        exit(1);
    }

    ++$matched;
}

if ($matched < 3) {
    fwrite(STDERR, "FAIL: Expected logs for readiness, GET, and HEAD.\n");
    exit(1);
}

echo "PASS: Structured entry-point failure records.\n";
' "$temporary_dir/server.log" \
    || fail "Structured logging assertions failed."

echo 'PASS: Application bootstrap failure HTTP checks.'
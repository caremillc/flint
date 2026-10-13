#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

command -v php >/dev/null
command -v curl >/dev/null

test -f public/index.php

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
    exit(1);
}

echo $address;
')"

base_url="http://$address"

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

status="$(curl --silent --show-error \
    --connect-timeout 2 \
    --max-time 5 \
    --head \
    --dump-header "$temporary_dir/head-headers" \
    --output /dev/null \
    --write-out '%{http_code}' \
    "$base_url/health")" \
    || fail "HEAD request failed."

[ "$status" = 500 ] || fail "HEAD must return 500."

tr -d '\r' <"$temporary_dir/head-headers" \
    >"$temporary_dir/head-headers-normalized"

grep -Eiq '^content-length: 21$' \
    "$temporary_dir/head-headers-normalized" \
    || fail "HEAD must preserve the representation length."

php bin/http-head-check.php "$address" /health 500 21 \
    || fail "Raw HEAD bootstrap-failure check failed."



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


check_capture_response() {
    local content_type="$1"
    local body_file="$2"
    local expected_status="$3"
    local expected_body="$4"
    local status

    status="$(curl --silent --show-error \
        --connect-timeout 2 \
        --max-time 10 \
        --request POST \
        --header "Content-Type: $content_type" \
        --header 'Expect:' \
        --data-binary "@$body_file" \
        --dump-header "$temporary_dir/capture-headers" \
        --output "$temporary_dir/capture-body" \
        --write-out '%{http_code}' \
        "$base_url/health")" \
        || fail "Request-capture HTTP request failed."

    [ "$status" = "$expected_status" ] \
        || fail "Capture check: expected $expected_status, received $status."

    printf '%s' "$expected_body" >"$temporary_dir/capture-expected"

    cmp -s "$temporary_dir/capture-expected" \
        "$temporary_dir/capture-body" \
        || fail "Capture check: unexpected response body."

    tr -d '\r' <"$temporary_dir/capture-headers" \
        >"$temporary_dir/capture-headers-normalized"

    grep -Eiq '^content-type: text/plain; charset=utf-8$' \
        "$temporary_dir/capture-headers-normalized" \
        || fail "Capture check: unexpected content type."

    grep -Eiq "^content-length: ${#expected_body}$" \
        "$temporary_dir/capture-headers-normalized" \
        || fail "Capture check: unexpected Content-Length."


        
    if [ "$expected_status" = 405 ]; then
        grep -Eiq '^allow: GET, HEAD$' \
            "$temporary_dir/capture-headers-normalized" \
            || fail "Accepted request body did not reach method routing."
    fi

    request_count=$((request_count + 1))
    echo "PASS: POST /health, capture boundary -> $status"
}

# The production RequestBodyReader currently defaults to 1 MiB.
php -r '
$limit = 1048576;

foreach ([
    "body-at-limit" => $limit,
    "body-over-limit" => $limit + 1,
] as $name => $length) {
    $written = file_put_contents(
        $argv[1] . "/" . $name,
        str_repeat("x", $length),
    );

    if ($written !== $length) {
        fwrite(STDERR, "Could not create the request body fixture.\n");
        exit(1);
    }
}
' "$temporary_dir" \
    || fail "Could not prepare request body fixtures."

# Capture succeeds, then the GET-only route rejects POST.
check_capture_response \
    'application/octet-stream' \
    "$temporary_dir/body-at-limit" \
    405 \
    'Method not allowed'

# Capture rejects the request before routing.
check_capture_response \
    'application/octet-stream' \
    "$temporary_dir/body-over-limit" \
    413 \
    'Payload too large'

printf '%s\r\n' '--flint-smoke--' >"$temporary_dir/multipart-body"

check_capture_response \
    'multipart/form-data; boundary=flint-smoke' \
    "$temporary_dir/multipart-body" \
    400 \
    'Bad request'

# Verify another request succeeds on the same server.
check_response GET /health 200 '{"status":"ok"}'


echo 'PASS: Application bootstrap failure HTTP checks.'

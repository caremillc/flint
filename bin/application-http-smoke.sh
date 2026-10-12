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

# Ask the operating system for an available local port.
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

application_name="Flint Smoke $(php -r 'echo bin2hex(random_bytes(8));')"
base_url="http://$address"

# Use predictable configuration for this server process only.
env -u PHP_CLI_SERVER_WORKERS \
    APP_NAME="$application_name" \
    APP_DEBUG=false \
    DATABASE_PORT=3306 \
    php \
        -d display_errors=0 \
        -d log_errors=1 \
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

    if curl --silent --fail \
        --connect-timeout 1 \
        --max-time 2 \
        "$base_url/" >"$temporary_dir/probe"; then
        printf '%s' "$application_name" >"$temporary_dir/expected"

        if cmp -s "$temporary_dir/expected" "$temporary_dir/probe"; then
            ready=true
            break
        fi
    fi

    sleep 0.1
done

if [ "$ready" != true ]; then
    fail "Application server did not become ready."
fi

request_count=0

check_response() {
    local method="$1"
    local path="$2"
    local expected_status="$3"
    local expected_body="$4"
    local status
    local -a options

    : >"$temporary_dir/body"

    options=(
        --silent
        --show-error
        --connect-timeout 2
        --max-time 5
        --dump-header "$temporary_dir/headers"
        --write-out '%{http_code}'
    )

    if [ "$method" = HEAD ]; then
        options+=(--head --output /dev/null)
    else
        options+=(--request "$method" --output "$temporary_dir/body")
    fi

    status="$(curl "${options[@]}" "$base_url$path")" \
        || fail "$method $path: request failed."

    [ "$status" = "$expected_status" ] \
        || fail "$method $path: expected $expected_status, received $status."

    printf '%s' "$expected_body" >"$temporary_dir/expected"

    cmp -s "$temporary_dir/expected" "$temporary_dir/body" \
        || fail "$method $path: unexpected response body."

    tr -d '\r' <"$temporary_dir/headers" \
        >"$temporary_dir/normalized-headers"

    if [ "$expected_status" = 405 ]; then
        grep -Eiq '^allow: GET, HEAD$' \
            "$temporary_dir/normalized-headers" \
            || fail "$method $path: incorrect Allow header."
    fi

    request_count=$((request_count + 1))
    echo "PASS: $method $path -> $status"
}

check_response GET / 200 "$application_name"
check_response GET /health 200 '{"status":"ok"}'
check_response HEAD /health 200 ''
check_response GET '/health?probe=1' 200 '{"status":"ok"}'
check_response POST /health 405 'Method not allowed'
check_response GET /missing 404 'Not found'
check_response HEAD /missing 404 ''

echo "PASS: $request_count application HTTP requests."

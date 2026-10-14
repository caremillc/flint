#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

command -v php >/dev/null
command -v curl >/dev/null

test -f public/index.php

temporary_dir="$(mktemp -d)"
server_pid=""

: >"$temporary_dir/expected-access.tsv"

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

application_name="Flint Smoke $(php -r 'echo bin2hex(random_bytes(8));')"
base_url="http://$address"

env -u PHP_CLI_SERVER_WORKERS \
    APP_NAME="$application_name" \
    APP_DEBUG=false \
    DATABASE_PORT=3306 \
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
    local request_id
    local -a options
    local request_body="${5-}"
    local content_type="${6-application/json}"

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

    if [ "$#" -ge 5 ]; then
        options+=(
            --header "Content-Type: $content_type"
            --header 'Expect:'
            --data-binary "$request_body"
        )
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

    grep -Eiq '^x-content-type-options: nosniff$' \
        "$temporary_dir/normalized-headers" \
        || fail "$method $path: missing nosniff response header."

    if [ "$expected_status" = 405 ]; then
        grep -Eiq '^allow: GET, HEAD$' \
            "$temporary_dir/normalized-headers" \
            || fail "$method $path: incorrect Allow header."
    fi

    request_id="$(awk '
        tolower($1) == "x-request-id:" { print $2 }
    ' "$temporary_dir/normalized-headers")"

    if [[ ! "$request_id" =~ ^[0-9a-f]{32}$ ]]; then
        fail "$method $path: expected exactly one valid request ID."
    fi

    printf '%s\t%s\t%s\n' \
        "$method" \
        "$status" \
        "$request_id" \
        >>"$temporary_dir/expected-access.tsv"

    request_count=$((request_count + 1))
    echo "PASS: $method $path -> $status"
}

check_json_response() {
    local expected_status="$1"
    local expected_body="$2"
    local request_body="$3"
    local content_type="${4-application/json}"
    local expected_media_type
    local actual_media_type
    local expected_length
    local actual_length

    check_response \
        POST \
        /hello \
        "$expected_status" \
        "$expected_body" \
        "$request_body" \
        "$content_type"

    if [ "$expected_status" = 200 ]; then
        expected_media_type='application/json'
    else
        expected_media_type='application/problem+json'
    fi

    actual_media_type="$(awk '
        tolower($1) == "content-type:" {
            sub(/^[^:]*:[ \t]*/, "")
            print
        }
    ' "$temporary_dir/normalized-headers")"

    [ "$actual_media_type" = "$expected_media_type" ] \
        || fail "POST /hello: unexpected response media type."

    # wc -c counts bytes, including UTF-8 multibyte sequences.
    expected_length="$(
        printf '%s' "$expected_body" |
            wc -c |
            tr -d '[:space:]'
    )"

    actual_length="$(awk '
        tolower($1) == "content-length:" {
            sub(/^[^:]*:[ \t]*/, "")
            print
        }
    ' "$temporary_dir/normalized-headers")"

    [ "$actual_length" = "$expected_length" ] \
        || fail "POST /hello: expected Content-Length $expected_length, received [$actual_length]."

    if grep -Eiq '^transfer-encoding:' \
        "$temporary_dir/normalized-headers"; then
        fail "POST /hello: unexpected Transfer-Encoding."
    fi
}

check_capture_response() {
    local content_type="$1"
    local body_file="$2"
    local expected_status="$3"
    local expected_body="$4"
    local status
    local curl_body_file="$body_file"

    # Make the filename inside curl's @file argument usable on Windows.
    if command -v cygpath >/dev/null 2>&1; then
        curl_body_file="$(cygpath -m "$body_file")"
    fi

    status="$(curl --silent --show-error \
        --connect-timeout 2 \
        --max-time 10 \
        --request POST \
        --header "Content-Type: $content_type" \
        --header 'Expect:' \
        --data-binary "@$curl_body_file" \
        --dump-header "$temporary_dir/capture-headers" \
        --output "$temporary_dir/capture-body" \
        --write-out '%{http_code}' \
        "$base_url/health")" \
        || fail "Request-capture HTTP request failed."

    [ "$status" = "$expected_status" ] \
        || fail "Capture check: expected $expected_status, received $status."

    printf '%s' "$expected_body" >"$temporary_dir/capture-expected"

    cmp -s \
        "$temporary_dir/capture-expected" \
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

    grep -Eiq '^x-content-type-options: nosniff$' \
        "$temporary_dir/capture-headers-normalized" \
        || fail "Capture response is missing the nosniff header."

    if [ "$expected_status" = 405 ]; then
        grep -Eiq '^allow: GET, HEAD$' \
            "$temporary_dir/capture-headers-normalized" \
            || fail "Accepted request body did not reach method routing."
    fi

    request_count=$((request_count + 1))
    echo "PASS: POST /health, capture boundary -> $status"
}

# Basic application routes.
check_response GET / 200 "$application_name"
check_response GET /health 200 '{"status":"ok"}'
check_response HEAD /health 200 ''
check_response GET '/health?probe=1' 200 '{"status":"ok"}'
check_response POST /health 405 'Method not allowed'
check_response GET /missing 404 'Not found'
check_response HEAD /missing 404 ''

# Parameterized routing.
check_response GET /hello/Ada 200 '{"greeting":"Hello, Ada"}'
check_response HEAD /hello/Ada 200 ''
check_response POST /hello/Ada 405 'Method not allowed'
check_response GET /hello/ 404 'Not found'
check_response GET '/hello/Ada?source=test' 200 '{"greeting":"Hello, Ada"}'
check_response GET '/hello/a%2Fb' 200 '{"greeting":"Hello, a%2Fb"}'

# Query validation and recovery.
check_response GET '/health?name=Ada&name=Grace' 400 'Bad request'
check_response GET '/health?name=Ada&%6Eame=Grace' 400 'Bad request'
check_response GET '/health?=value' 400 'Bad request'
check_response HEAD '/health?name=Ada&name=Grace' 400 ''
check_response GET /health 200 '{"status":"ok"}'

# Handler-level query options.
check_response GET '/hello/Ada?style=formal' 200 '{"greeting":"Welcome, Ada"}'
check_response HEAD '/hello/Ada?style=formal' 200 ''
check_response GET '/hello/Ada?style=unknown' 400 'Bad request'
check_response GET '/hello/Ada?style=' 400 'Bad request'

json_bad_request='{"type":"about:blank","title":"Bad Request","status":400,"detail":"The body must contain a valid JSON object."}'
# json_invalid_fields='{"type":"about:blank","title":"Unprocessable Content","status":422}'
json_invalid_fields='{"type":"about:blank","title":"Unprocessable Content","status":422,"invalid_fields":["name"]}'
json_invalid_style='{"type":"about:blank","title":"Unprocessable Content","status":422,"invalid_fields":["style"]}'

json_unsupported_media='{"type":"about:blank","title":"Unsupported Media Type","status":415,"detail":"Use application/json without Content-Encoding."}'

check_json_response 200 '{"greeting":"Hello, Ada"}' \
    '{"name":"Ada"}'

check_json_response 200 '{"greeting":"Welcome, Ada"}' \
    '{"name":"Ada","style":"formal"}'

check_json_response 400 "$json_bad_request" \
    '{"name":'

check_json_response 400 "$json_bad_request" \
    '[]'

check_json_response 422 "$json_invalid_fields" \
    '{"name":42}'

check_json_response \
    422 \
    "$json_invalid_style" \
    '{"name":"Ada","style":"unknown"}'

check_json_response \
    422 \
    "$json_invalid_fields" \
    '{"name":123456789012345678901234567890}'

check_json_response \
    200 \
    '{"greeting":"Hello, 123456789012345678901234567890"}' \
    '{"name":"123456789012345678901234567890"}'
    
check_json_response 415 "$json_unsupported_media" \
    '{"name":"Ada"}' 'text/plain'

# Media-type matching is case-insensitive; parameters are accepted.
check_json_response \
    200 \
    '{"greeting":"Hello, Ada"}' \
    '{"name":"Ada"}' \
    'Application/JSON; charset=utf-8'

# Exactly 100 ASCII bytes are accepted.
ascii_name="$(php -r 'echo str_repeat("a", 100);')"

check_json_response \
    200 \
    "{\"greeting\":\"Hello, $ascii_name\"}" \
    "{\"name\":\"$ascii_name\"}"

# One byte beyond the limit is rejected.
check_json_response \
    422 \
    "$json_invalid_fields" \
    "{\"name\":\"${ascii_name}a\"}"

# U+00E9 occupies two bytes in UTF-8.
# Generate bytes explicitly so the script's file encoding is irrelevant.
unicode_name="$(php -r 'echo str_repeat("\xC3\xA9", 50);')"
unicode_extra="$(php -r 'echo "\xC3\xA9";')"

check_json_response \
    200 \
    "{\"greeting\":\"Hello, $unicode_name\"}" \
    "{\"name\":\"$unicode_name\"}"

check_json_response \
    422 \
    "$json_invalid_fields" \
    "{\"name\":\"${unicode_name}${unicode_extra}\"}"

# Confirm another valid request succeeds after rejection.
check_json_response \
    200 \
    '{"greeting":"Welcome, Grace"}' \
    '{"name":"Grace","style":"formal"}'

# Disable MSYS argument conversion only for these HTTP-path arguments.
MSYS2_ARG_CONV_EXCL='*' php bin/http-head-check.php \
    "$address" /health 200 15 \
    || fail "Raw HEAD health check failed."

MSYS2_ARG_CONV_EXCL='*' php bin/http-head-check.php \
    "$address" /missing 404 9 \
    || fail "Raw HEAD missing-route check failed."

request_count=$((request_count + 2))

# Prepare exact-limit and over-limit bodies for the default 1 MiB reader.
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

check_capture_response \
    'application/octet-stream' \
    "$temporary_dir/body-at-limit" \
    405 \
    'Method not allowed'

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

# The same server must handle a normal request after capture rejections.
check_response GET /health 200 '{"status":"ok"}'

# Only check_response() writes entries into the correlation manifest.
php bin/verify-access-log.php \
    "$temporary_dir/server.log" \
    "$temporary_dir/expected-access.tsv" \
    || fail "Access-log correlation failed."

echo "PASS: $request_count application HTTP requests."
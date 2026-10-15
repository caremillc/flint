#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

command -v php >/dev/null 2>&1 \
    || fail "PHP is not available on PATH."

command -v composer >/dev/null 2>&1 \
    || fail "Composer is not available on PATH."

command -v curl >/dev/null 2>&1 \
    || fail "curl is required by the HTTP smoke tests."

test -f composer.json \
    || fail "Root composer.json is missing."

test -f framework/composer.json \
    || fail "framework/composer.json is missing."

# Require lock files so setup installs the recorded dependency versions.
test -f composer.lock \
    || fail "Root composer.lock is missing. Restore it from the repository."

test -f framework/composer.lock \
    || fail "framework/composer.lock is missing. Restore it from the repository."

test -f bin/doctor.sh \
    || fail "bin/doctor.sh is missing."

echo 'Installing framework development dependencies...'

composer --working-dir=framework install \
    --no-interaction \
    --prefer-dist \
    --dev

echo 'Installing application dependencies...'

composer install \
    --no-interaction \
    --prefer-dist \
    --dev

echo 'Checking the development environment...'

bash bin/doctor.sh

echo 'PASS: Development setup completed.'
echo 'Run composer check:all to verify the application and framework.'

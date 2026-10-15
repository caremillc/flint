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

printf 'PHP executable: %s\n' "$(command -v php)"
php -r 'echo "PHP version: ", PHP_VERSION, PHP_EOL;'

# Check application autoloading in its own PHP process.
php -r '
$autoload = getcwd() . "/vendor/autoload.php";

if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "FAIL: Root vendor/autoload.php is missing. Run bash bin/setup.sh.\n",
    );
    exit(1);
}

require $autoload;

$classes = [
    Flint\Foundation\Application::class,
    Flint\Routing\Router::class,
    Flint\Routing\RouteUrlGenerator::class,
    Flint\Http\JsonRequestDecoder::class,
    App\Http\Handlers\JsonGreetingHandler::class,
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "FAIL: Application autoloader cannot load " . $class . ".\n");
        exit(1);
    }
}

$router = new ReflectionClass(Flint\Routing\Router::class);

echo "PASS: Application and framework classes load through root autoloading.\n";
echo "Router source: ", $router->getFileName(), PHP_EOL;
'

# Check framework autoloading separately, without loading root dependencies.
(
    cd framework || exit 1

    php -r '
    $autoload = getcwd() . "/vendor/autoload.php";

    if (!is_file($autoload)) {
        fwrite(
            STDERR,
            "FAIL: Framework vendor/autoload.php is missing. Run bash bin/setup.sh.\n",
        );
        exit(1);
    }

    require $autoload;

    $classes = [
        Flint\Routing\Router::class,
        PHPUnit\Framework\TestCase::class,
        PhpCsFixer\Console\Application::class,
        Composer\XdebugHandler\XdebugHandler::class,
    ];

    foreach ($classes as $class) {
        if (!class_exists($class)) {
            fwrite(STDERR, "FAIL: Framework autoloader cannot load " . $class . ".\n");
            exit(1);
        }
    }

    foreach ([
        "vendor/bin/phpunit",
        "vendor/bin/php-cs-fixer",
        "vendor/bin/phpstan",
    ] as $tool) {
        if (!is_file($tool)) {
            fwrite(STDERR, "FAIL: Missing framework tool: " . $tool . "\n");
            exit(1);
        }
    }

    echo "PASS: Framework autoloading and development tool entry points exist.\n";
    '
)

composer check-platform-reqs
composer --working-dir=framework check-platform-reqs

echo 'PASS: Development environment checks completed.'

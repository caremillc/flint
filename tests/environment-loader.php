<?php

declare(strict_types=1);

use App\Configuration\EnvironmentLoader;
use Flint\Configuration\Exception\ConfigurationException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function environmentLoaderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/flint-env-' . bin2hex(random_bytes(8));

if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create the environment fixture.');
}

$path = $directory . '/.env';
$loader = new EnvironmentLoader();
$originalProcessName = getenv('APP_NAME');

try {
    $missing = $loader->load($directory, []);

    environmentLoaderCheck(
        !$missing->has('APP_NAME'),
        'An absent optional .env file must be accepted.',
    );

    $fixture = <<<'ENV'
APP_NAME="Local Flint"
APP_DEBUG=false
DATABASE_PASSWORD='literal$secret'
DATABASE_PORT=3306
ENV;

    if (file_put_contents($path, $fixture) !== strlen($fixture)) {
        throw new RuntimeException('Could not write the environment fixture.');
    }

    $local = $loader->load($directory, []);

    environmentLoaderCheck(
        $local->string('APP_NAME') === 'Local Flint'
            && $local->boolean('APP_DEBUG') === false
            && $local->string('DATABASE_PASSWORD') === 'literal$secret',
        'Quoted values and typed conversion must work.',
    );

    $external = $loader->load($directory, [
        'APP_NAME' => 'Deployment',
        'DATABASE_PASSWORD' => '',
        'DATABASE_PORT' => '0',
    ]);

    environmentLoaderCheck(
        $external->string('APP_NAME') === 'Deployment'
            && $external->string('DATABASE_PASSWORD') === ''
            && $external->integer('DATABASE_PORT') === 0,
        'External values, including empty and zero, must override .env.',
    );

    environmentLoaderCheck(
        $local->string('APP_NAME') === 'Local Flint'
            && getenv('APP_NAME') === $originalProcessName,
        'Loads must preserve earlier snapshots and the process environment.',
    );

    $disabled = $loader->load($directory, [], false);

    environmentLoaderCheck(
        !$disabled->has('APP_NAME'),
        'Tests must be able to disable local dotenv loading.',
    );

    $malformed = "DATABASE_PASSWORD=\"unterminated-secret\n";

    if (file_put_contents($path, $malformed) !== strlen($malformed)) {
        throw new RuntimeException('Could not write the malformed fixture.');
    }

    $caught = null;

    try {
        $loader->load($directory, []);
    } catch (ConfigurationException $exception) {
        $caught = $exception;
    }

    environmentLoaderCheck(
        $caught !== null
            && $caught->getMessage()
                === 'Could not load .env. Check its syntax and read permissions.'
            && $caught->getPrevious() === null,
        'Malformed dotenv files must fail without exposing parser details.',
    );
} finally {
    if (is_file($path)) {
        unlink($path);
    }

    rmdir($directory);
}

echo 'PASS: Dotenv parsing, precedence, isolation, optional loading, and safe failure.'
    . PHP_EOL;
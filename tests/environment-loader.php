<?php

declare (strict_types = 1);

use App\Configuration\EnvironmentLoader;
use Flint\Configuration\Exception\ConfigurationException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function environmentLoaderCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/flint-env-' . bin2hex(random_bytes(8));

if (! mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create the environment fixture.');
}

$path                = $directory . '/.env';
$loader              = new EnvironmentLoader();
$originalProcessName = getenv('APP_NAME');

try {
    $missing = $loader->load($directory, []);

    environmentLoaderCheck(
        ! $missing->has('APP_NAME'),
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
        'APP_NAME'          => 'Deployment',
        'DATABASE_PASSWORD' => '',
        'DATABASE_PORT'     => '0',
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
        ! $disabled->has('APP_NAME'),
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

    $validCases = [
        'multiline value'          => [
            'contents' => "APP_NAME=\"Local\nFlint\"\n",
            'expected' => "Local\nFlint",
        ],
        'no final newline'         => [
            'contents' => 'APP_NAME="Local Flint"',
            'expected' => 'Local Flint',
        ],
        'CRLF multiline value'     => [
            'contents' => "APP_NAME=\"Local\r\nFlint\"\r\n",
            'expected' => "Local\nFlint",
        ],
        'file-local interpolation' => [
            'contents' => 'BASE=Local' . "\n"
            . 'APP_NAME="${BASE} Flint"' . "\n",
            'expected' => 'Local Flint',
        ],
        'quote inside a comment'   => [
            'contents' => "# Ignore this unfinished \" quote\n"
            . "APP_NAME=Flint\n",
            'expected' => 'Flint',
        ],
    ];

    foreach ($validCases as $label => $case) {
        $contents = $case['contents'];

        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write the valid fixture.');
        }

        $loaded = $loader->load($directory, []);

        environmentLoaderCheck(
            $loaded->string('APP_NAME') === $case['expected'],
            sprintf('Valid dotenv syntax must remain supported: %s.', $label),
        );
    }

    $malformedCases = [
        'unfinished double quote with newline'    =>
        "DATABASE_PASSWORD=\"unterminated-secret\n",

        'unfinished double quote without newline' =>
        'DATABASE_PASSWORD="unterminated-secret',

        'unfinished double quote with CRLF'       =>
        "DATABASE_PASSWORD=\"unterminated-secret\r\n",

        'unfinished value after a valid entry'    =>
        "APP_NAME=Flint\nDATABASE_PASSWORD=\"unterminated-secret\n",

        'unfinished single quote'                 =>
        "DATABASE_PASSWORD='unterminated-secret\n",

        'invalid variable name'                   =>
        "INVALID KEY=value\n",
    ];

    foreach ($malformedCases as $label => $contents) {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write the malformed fixture.');
        }

        $caught = null;

        try {
            $loader->load($directory, []);
        } catch (ConfigurationException $exception) {
            $caught = $exception;
        }

        environmentLoaderCheck(
            $caught !== null,
            sprintf('Malformed dotenv syntax must be rejected: %s.', $label),
        );

        if ($caught !== null) {
            environmentLoaderCheck(
                $caught->getMessage()
                === 'Could not load .env. Check its syntax and read permissions.'
                && $caught->getPrevious() === null,
                sprintf('Parser details must remain private: %s.', $label),
            );
        }
    }

    // Loading can still be disabled even when the file is malformed.
    $disabledMalformed = $loader->load(
        $directory,
        ['APP_NAME' => 'External'],
        false,
    );

    environmentLoaderCheck(
        $disabledMalformed->string('APP_NAME') === 'External',
        'Disabled dotenv loading must bypass malformed file contents.',
    );

    // A failed load must not prevent a subsequent valid load.
    $recovery = "APP_NAME=Recovered\n";

    if (file_put_contents($path, $recovery) !== strlen($recovery)) {
        throw new RuntimeException('Could not write the recovery fixture.');
    }

    environmentLoaderCheck(
        $loader->load($directory, [])->string('APP_NAME') === 'Recovered',
        'Environment loading must recover after malformed input.',
    );

} finally {
    if (is_file($path)) {
        unlink($path);
    }

    rmdir($directory);
}

echo 'PASS: Dotenv parsing, precedence, isolation, optional loading, and safe failure.'
    . PHP_EOL;

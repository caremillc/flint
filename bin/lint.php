<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$directories = [
    'app',
    'bin',
    'bootstrap',
    'config',
    'public',
    'tests',
];

$files = [];

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;

    // Some application directories, such as public, may not exist yet.
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if (
            !$file->isFile()
            || strtolower($file->getExtension()) !== 'php'
        ) {
            continue;
        }

        $files[] = $file->getPathname();
    }
}

sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, 'No application PHP files were found.' . PHP_EOL);

    exit(1);
}

foreach ($files as $file) {
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
    );

    if (!is_resource($process)) {
        fwrite(
            STDERR,
            'Could not start the PHP syntax checker.' . PHP_EOL,
        );

        exit(1);
    }

    fclose($pipes[0]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        exit(1);
    }
}

echo sprintf(
    'PASS: Syntax checked %d application PHP files.',
    count($files),
) . PHP_EOL;

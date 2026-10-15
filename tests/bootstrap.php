<?php

declare(strict_types=1);

use Flint\Foundation\Application;

$bootstrap = dirname(__DIR__) . '/bootstrap/app.php';

$first = require $bootstrap;
$second = require $bootstrap;

if (!$first instanceof Application || !$second instanceof Application) {
    throw new RuntimeException(
        'The bootstrap must return a Flint application.',
    );
}

if ($first === $second) {
    throw new RuntimeException(
        'Each bootstrap invocation must construct an independent application.',
    );
}

$expectedBasePath = realpath(dirname(__DIR__));

if ($expectedBasePath === false) {
    throw new RuntimeException(
        'The skeleton root directory could not be resolved.',
    );
}

if (
    $first->basePath() !== $expectedBasePath
    || $second->basePath() !== $expectedBasePath
) {
    throw new RuntimeException(
        'The bootstrap must use the skeleton directory as its application root.',
    );
}

if (!$first->isBooted() || !$second->isBooted()) {
    throw new RuntimeException(
        'Each bootstrap invocation must return a booted application.',
    );
}

if ($first->container() === $second->container()) {
    throw new RuntimeException(
        'Independent applications must not share a container.',
    );
}

// Calling boot again must preserve the successfully booted state.
$first->boot();

if (!$first->isBooted() || !$second->isBooted()) {
    throw new RuntimeException(
        'Repeated boot must preserve independent application state.',
    );
}


fwrite(
    STDOUT,
    "PASS: Composer bootstrap, application root, independent containers, and booted applications.\n",
);

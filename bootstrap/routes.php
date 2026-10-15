<?php

declare(strict_types=1);

use App\Http\HttpApplicationFactory;
use Flint\Foundation\Application;

$application = require __DIR__ . '/app.php';

if (!$application instanceof Application) {
    throw new RuntimeException(
        'Application bootstrap returned an invalid value.',
    );
}

return HttpApplicationFactory::routes($application);
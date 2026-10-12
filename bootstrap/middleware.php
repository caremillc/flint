<?php

declare(strict_types=1);

use App\Http\Middleware\ResponseHeadersMiddleware;

return [
    new ResponseHeadersMiddleware(),
];

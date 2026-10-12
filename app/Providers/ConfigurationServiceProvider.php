<?php

declare(strict_types=1);

namespace App\Providers;

use Flint\Configuration\Configuration;
use Flint\Container\Container;
use Flint\Foundation\ServiceProvider;

final class ConfigurationServiceProvider implements ServiceProvider
{
    public function __construct(
        private readonly Configuration $configuration,
    ) {
    }

    public function register(Container $container): void
    {
        $container->instance(
            Configuration::class,
            $this->configuration,
        );
    }
}

<?php

/**
 * Laravel service provider for the Strands PHP Client.
 *
 * Registers StrandsClientFactory, binds the default StrandsClient, and
 * creates named "strands.client.<name>" bindings for each configured agent.
 */

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Integration\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

class StrandsServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/strands.php',
            'strands',
        );

        $this->app->singleton(StrandsClientFactory::class, function (Application $app): StrandsClientFactory {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array<string, array{endpoint: string, auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string}, timeout: int, connect_timeout?: int, max_retries?: int, retry_delay_ms?: int}> $agents */
            $agents = $config->get('strands.agents', []);

            /** @var LoggerInterface $logger */
            $logger = $app->make(LoggerInterface::class);

            return new StrandsClientFactory($agents, $logger);
        });

        $this->app->singleton(StrandsClient::class, function (Application $app): StrandsClient {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var string $default */
            $default = $config->get('strands.default', 'default');

            return $app->make(StrandsClientFactory::class)->create($default);
        });

        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        /** @var array<string, array<string, mixed>> $agents */
        $agents = $config->get('strands.agents', []);

        foreach (array_keys($agents) as $name) {
            $binding = 'strands.client.' . $name;
            $this->app->singleton($binding, function (Application $app) use ($name): StrandsClient {
                return $app->make(StrandsClientFactory::class)->create($name);
            });
        }
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/strands.php' => $this->app->configPath('strands.php'),
            ], 'strands-config');
        }
    }
}

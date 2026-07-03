<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\Integration\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

/**
 * Wires Strands clients into a Laravel application's service container.
 *
 * Merges the package config, registers the shared factory, binds the default
 * StrandsClient for type-hint injection, and adds a "strands.client.<name>"
 * binding per configured agent. Also publishes the config file for `artisan
 * vendor:publish`. Laravel calls this automatically at boot.
 */
class StrandsServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void No returned value; updates client or observer state.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/strands.php',
            'strands',
        );

        $this->app->singleton(StrandsClientFactory::class, function (Application $application): StrandsClientFactory {
            /** @var ConfigRepository $config validated before app code uses it. */
            $config = $application->make('config');

            /** @var array<string, array{endpoint: string, auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string, region?: string|null, service?: string, access_key_id?: string|null, secret_access_key?: string|null, session_token?: string|null}, timeout: int, connect_timeout?: int, max_retries?: int, retry_delay_ms?: int}> $agents validated before app code uses it. */
            $agents = $config->get('strands.agents', []);

            /** @var LoggerInterface $logger validated before app code uses it. */
            $logger = $application->make(LoggerInterface::class);

            // Resolve any middleware tagged with 'strands.middleware'.
            // To register middleware in your app:
            //   $this->app->tag([MyTracingMiddleware::class], 'strands.middleware');
            /** @var list<RequestMiddleware> $middleware validated before app code uses it. */
            $middleware = $application->tagged('strands.middleware');

            // Response observers receive parsed terminal data for metrics/tracing.
            // Middleware that implements ResponseObserver is also auto-detected by
            // StrandsClient, so existing strands.middleware registrations keep working.
            /** @var list<ResponseObserver> $responseObservers validated before app code uses it. */
            $responseObservers = $application->tagged('strands.response_observer');

            return new StrandsClientFactory($agents, $logger, $middleware, $responseObservers);
        });

        $this->app->singleton(StrandsClient::class, function (Application $application): StrandsClient {
            /** @var ConfigRepository $config validated before app code uses it. */
            $config = $application->make('config');

            /** @var string $default validated before app code uses it. */
            $default = $config->get('strands.default', 'default');

            return $application->make(StrandsClientFactory::class)->create($default);
        });

        /** @var ConfigRepository $config validated before app code uses it. */
        $config = $this->app->make('config');

        /** @var array<string, array<string, mixed>> $agents validated before app code uses it. */
        $agents = $config->get('strands.agents', []);

        // Give every configured agent its own container binding so an app can inject a
        // specific one (e.g. app('strands.client.support')) alongside the default client.
        foreach (array_keys($agents) as $name) {
            $binding = 'strands.client.' . $name;
            $this->app->singleton($binding, function (Application $application) use ($name): StrandsClient {
                return $application->make(StrandsClientFactory::class)->create($name);
            });
        }
    }

    /**
     * Publish the config file so `artisan vendor:publish` can copy it into the app.
     *
     * @return void No returned value; updates client or observer state.
     */
    public function boot(): void
    {
        // Only offer the publishable config from the CLI, where vendor:publish runs.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/strands.php' => $this->app->configPath('strands.php'),
            ], 'strands-config');
        }
    }
}

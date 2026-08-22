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
 * It registers the shared factory, default client, and one named binding for each configured agent.
 * Laravel runs it at boot; apps use its bindings for injection and can publish the package config.
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

            /**
             * @var array<string, array{
             *     endpoint: string,
             *     auth: array{
             *         driver: string,
             *         api_key?: string|null,
             *         header_name?: string,
             *         value_prefix?: string,
             *         region?: string|null,
             *         service?: string,
             *         access_key_id?: string|null,
             *         secret_access_key?: string|null,
             *         session_token?: string|null
             *     },
             *     timeout: int,
             *     connect_timeout?: int,
             *     max_retries?: int,
             *     retry_delay_ms?: int
             * }> $agents Validated framework configuration used to build app clients.
             */
            $agents = $config->get('strands.agents', []);

            /** @var LoggerInterface $logger validated before app code uses it. */
            $logger = $application->make(LoggerInterface::class);

            // Resolve middleware tagged as strands.middleware; for example, an app may tag MyTracingMiddleware during registration.
            /** @var list<RequestMiddleware> $middleware validated before app code uses it. */
            $middleware = $application->tagged('strands.middleware');

            // Response observers receive parsed terminal data for metrics and tracing.
            // StrandsClient also detects observers in the middleware list, preserving existing app registrations.
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

<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Strands Service Provider behavior for app integrations.
 *
 * Use this file when changing Strands Service Provider or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Integration\Laravel\StrandsServiceProvider;
use StrandsPhpClient\Integration\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

/**
 * Exercises Strands Service Provider through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class StrandsServiceProviderTest extends TestCase
{
    /**
     * Confirms config file exists so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigFileExists(): void
    {
        $configPath = __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertFileExists($configPath);
    }

    /**
     * Confirms config returns array so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigReturnsArray(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertIsArray($config);
    }

    /**
     * Confirms config has default key so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigHasDefaultKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('default', $config);
    }

    /**
     * Confirms config has agents key so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigHasAgentsKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('agents', $config);
        $this->assertIsArray($config['agents']);
    }

    /**
     * Confirms config default agent has required keys so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigDefaultAgentHasRequiredKeys(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $agent = $config['agents']['default'];

        $this->assertArrayHasKey('endpoint', $agent);
        $this->assertArrayHasKey('auth', $agent);
        $this->assertArrayHasKey('timeout', $agent);
        $this->assertArrayHasKey('connect_timeout', $agent);
        $this->assertArrayHasKey('max_retries', $agent);
        $this->assertArrayHasKey('retry_delay_ms', $agent);
    }

    /**
     * Confirms config auth has required keys so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigAuthHasRequiredKeys(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $auth = $config['agents']['default']['auth'];

        $this->assertArrayHasKey('driver', $auth);
        $this->assertArrayHasKey('api_key', $auth);
        $this->assertArrayHasKey('header_name', $auth);
        $this->assertArrayHasKey('value_prefix', $auth);
    }

    /**
     * Confirms config defaults so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testConfigDefaults(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $agent = $config['agents']['default'];

        $this->assertSame(120, $agent['timeout']);
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
        $this->assertSame('Authorization', $agent['auth']['header_name']);
        $this->assertSame('Bearer ', $agent['auth']['value_prefix']);
    }

    /**
     * Confirms tagged middleware runs for the default client so framework users can register request hooks.
     *
     * @return void
     */
    public function testTaggedMiddlewareRunsForResolvedDefaultClient(): void
    {
        $middlewareFailure = new \RuntimeException('Tagged middleware reached the default client.');
        $requestMiddleware = $this->createMock(RequestMiddleware::class);
        $requestMiddleware
            ->expects($this->once())
            ->method('beforeRequest')
            ->willThrowException($middlewareFailure);

        $app = $this->createRegisteredApplication([
            'default' => 'primary',
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
            ],
        ], [$requestMiddleware]);

        $strandsClient = $app->make(StrandsClient::class);
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);

        $this->expectExceptionObject($middlewareFailure);
        $strandsClient->invoke('Hello');
    }

    /**
     * Confirms registration resolves the factory, default client, and named clients so framework users can inject the intended assistant.
     *
     * @return void
     */
    public function testRegisterResolvesFactoryDefaultAndNamedClientBindings(): void
    {
        $app = $this->createRegisteredApplication([
            'default' => 'analyst',
            'agents' => [
                'analyst' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
                'skeptic' => [
                    'endpoint' => 'http://agent:8001',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
            ],
        ]);

        $factory = $app->make(StrandsClientFactory::class);
        $defaultClient = $app->make(StrandsClient::class);
        $analystClient = $app->make('strands.client.analyst');
        $skepticClient = $app->make('strands.client.skeptic');

        $this->assertInstanceOf(StrandsClientFactory::class, $factory);
        $this->assertInstanceOf(StrandsClient::class, $defaultClient);
        $this->assertInstanceOf(StrandsClient::class, $analystClient);
        $this->assertInstanceOf(StrandsClient::class, $skepticClient);

        // singleton() bindings should resolve to the same instance per key
        $this->assertSame($factory, $app->make(StrandsClientFactory::class));
        $this->assertSame($defaultClient, $app->make(StrandsClient::class));
        $this->assertSame($analystClient, $app->make('strands.client.analyst'));
        $this->assertSame($skepticClient, $app->make('strands.client.skeptic'));

        // default should use the configured default agent name
        $this->assertSame('http://agent:8000', $this->extractEndpoint($defaultClient));
        $this->assertSame('http://agent:8000', $this->extractEndpoint($analystClient));
        $this->assertSame('http://agent:8001', $this->extractEndpoint($skepticClient));
        $this->assertNotSame($analystClient, $skepticClient);
    }

    /**
     * Build the small Laravel container used to exercise package registration as an application would.
     * Empty middleware means the app registered no request hooks.
     *
     * @param array{
     *   default: string,
     *   agents: array<string, array{
     *     endpoint: string,
     *     auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string},
     *     timeout: int,
     *     connect_timeout?: int,
     *     max_retries?: int,
     *     retry_delay_ms?: int
     *   }>
     * } $strandsConfig Required Laravel settings; an empty map cannot identify a default agent.
     * @param list<RequestMiddleware> $taggedMiddleware Tagged app hooks; empty means requests run without middleware.
     * @return Application Registered test application; never null or empty.
     */
    private function createRegisteredApplication(array $strandsConfig, array $taggedMiddleware = []): Application
    {
        /** @var array<string, mixed> $configState validated before app code uses it. */
        $configState = ['strands' => $strandsConfig];

        /** @var array<string, callable(Application): mixed> $bindings validated before app code uses it. */
        $bindings = [];

        /** @var array<string, mixed> $instances validated before app code uses it. */
        $instances = [];

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnCallback(
            function ($key, $default = null) use (&$configState): mixed {
                // Laravel returns the caller's fallback when app code asks for an empty or non-string config key.
                if (!is_string($key) || $key === '') {
                    return $default;
                }

                return $this->getNestedConfigValue($configState, $key, $default);
            },
        );
        $config->method('set')->willReturnCallback(function ($key, $configValue = null) use (&$configState): void {
            // Laravel accepts a map when package registration writes several config values in one call.
            if (is_array($key)) {
                // Apply every named config entry so the test container mirrors Laravel's repository behavior.
                foreach ($key as $nestedKey => $nestedValue) {
                    // Empty or numeric keys cannot identify app config and are ignored just as an invalid caller key would be.
                    if (is_string($nestedKey) && $nestedKey !== '') {
                        $this->setNestedConfigValue($configState, $nestedKey, $nestedValue);
                    }
                }

                return;
            }

            // A single named key updates the value the service provider will read when it creates the client.
            if (is_string($key) && $key !== '') {
                $this->setNestedConfigValue($configState, $key, $configValue);
            }
        });

        $app = $this->createMock(Application::class);
        $app->method('tagged')->willReturnCallback(
            function (string $serviceTag) use ($taggedMiddleware): iterable {
                // The package asks for this tag when an app has registered tracing, headers, or other request hooks.
                if ($serviceTag === 'strands.middleware') {
                    return $taggedMiddleware;
                }

                return [];
            },
        );
        $app->method('singleton')->willReturnCallback(
            function ($abstract, $concrete = null) use (&$bindings, $app): Application {
                // A malformed binding would make Laravel unable to resolve the client the user's controller requested.
                if (!is_string($abstract) || !is_callable($concrete)) {
                    throw new \RuntimeException('Invalid singleton binding in test harness.');
                }

                $bindings[$abstract] = $concrete;

                return $app;
            },
        );
        $app->method('make')->willReturnCallback(
            function ($abstract) use (&$bindings, &$instances, $config, $app): mixed {
                // The package resolves Laravel's config repository while registering agent definitions.
                if ($abstract === 'config') {
                    return $config;
                }

                // Client creation asks the container for a logger that records failures without changing the user response.
                if ($abstract === LoggerInterface::class) {
                    return new NullLogger();
                }

                // An empty or non-string service key cannot identify the client dependency app code requested.
                if (!is_string($abstract) || $abstract === '') {
                    throw new \RuntimeException('Invalid abstract requested in test harness.');
                }

                // Singleton services return the same client or factory instance on later application requests.
                if (array_key_exists($abstract, $instances)) {
                    return $instances[$abstract];
                }

                // A missing binding means the package did not register something the consuming app attempted to resolve.
                if (!array_key_exists($abstract, $bindings)) {
                    throw new \RuntimeException(sprintf('No binding found for "%s".', $abstract));
                }

                $instances[$abstract] = $bindings[$abstract]->__invoke($app);

                return $instances[$abstract];
            },
        );

        $strandsServiceProvider = new StrandsServiceProvider($app);
        $strandsServiceProvider->register();

        return $app;
    }

    /**
     * Read one dotted Laravel config path while the test container resolves an agent.
     * Use it to mirror values such as strands.default that application code expects from the real repository.
     *
     * @param array<string, mixed> $config client settings chosen by the application.
     * @param string $configPath Dotted config path; empty cannot resolve a setting and returns the fallback.
     * @param mixed $default Fallback for missing config; null means the caller wants absence represented as null.
     * @return mixed Configured value or the fallback; null only when the value is missing and the fallback is null.
     */
    private function getNestedConfigValue(array $config, string $configPath, mixed $default = null): mixed
    {
        $resolvedConfigValue = $config;

        // Resolve each segment so a controller asking for strands.default sees the same value as in Laravel.
        foreach (explode('.', $configPath) as $configSegment) {
            // Missing or scalar intermediate values mean this config path is absent, so return the caller's fallback.
            if (!is_array($resolvedConfigValue) || !array_key_exists($configSegment, $resolvedConfigValue)) {
                return $default;
            }

            $resolvedConfigValue = $resolvedConfigValue[$configSegment];
        }

        return $resolvedConfigValue;
    }

    /**
     * Write one dotted Laravel config path while the package registers an agent.
     * Use it to mirror package defaults and explicit null or empty values exactly as the real repository stores them.
     *
     * @param array<string, mixed> $config client settings chosen by the application.
     * @param string $configPath Dotted config path; empty means there is no setting to update.
     * @param mixed $configValue Value stored for the app; null or empty remains an intentional configured value.
     * @return void No returned value; updates client or observer state.
     */
    private function setNestedConfigValue(array &$config, string $configPath, mixed $configValue): void
    {
        $configSegments = explode('.', $configPath);
        $finalConfigSegment = array_pop($configSegments);

        // An empty final segment cannot name a Laravel setting, so leave the app config unchanged.
        if ($finalConfigSegment === null || $finalConfigSegment === '') {
            return;
        }

        $currentConfigNode = &$config;

        // Create each missing parent map so the requested dotted setting can be stored for client registration.
        foreach ($configSegments as $configSegment) {
            // A missing or scalar parent becomes a map, matching Laravel's nested configuration behavior.
            if (!isset($currentConfigNode[$configSegment]) || !is_array($currentConfigNode[$configSegment])) {
                $currentConfigNode[$configSegment] = [];
            }

            /** @var array<string, mixed> $currentConfigNode Parent map used by the next dotted segment. */
            $currentConfigNode = &$currentConfigNode[$configSegment];
        }

        $currentConfigNode[$finalConfigSegment] = $configValue;
    }

    /**
     * Extract endpoint for assertions.
     *
     * @param StrandsClient $strandsClient Client instance inspected by the test helper.
     * @return string String value produced by the helper.
     */
    private function extractEndpoint(StrandsClient $strandsClient): string
    {
        $reflectionProperty = new \ReflectionProperty(StrandsClient::class, 'config');
        $reflectionProperty->setAccessible(true);

        $config = $reflectionProperty->getValue($strandsClient);
        $this->assertInstanceOf(StrandsConfig::class, $config);

        return $config->endpoint;
    }
}

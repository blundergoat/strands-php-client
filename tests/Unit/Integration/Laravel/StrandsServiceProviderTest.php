<?php

declare(strict_types=1);

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

class StrandsServiceProviderTest extends TestCase
{
    public function testConfigFileExists(): void
    {
        $configPath = __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertFileExists($configPath);
    }

    public function testConfigReturnsArray(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertIsArray($config);
    }

    public function testConfigHasDefaultKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('default', $config);
    }

    public function testConfigHasAgentsKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('agents', $config);
        $this->assertIsArray($config['agents']);
    }

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

    public function testConfigAuthHasRequiredKeys(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $auth = $config['agents']['default']['auth'];

        $this->assertArrayHasKey('driver', $auth);
        $this->assertArrayHasKey('api_key', $auth);
        $this->assertArrayHasKey('header_name', $auth);
        $this->assertArrayHasKey('value_prefix', $auth);
    }

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

    public function testFactoryReceivesTaggedMiddleware(): void
    {
        $mw = $this->createMock(RequestMiddleware::class);

        $app = $this->createRegisteredApplication([
            'default' => 'primary',
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
            ],
        ], [$mw]);

        $factory = $app->make(StrandsClientFactory::class);
        $this->assertInstanceOf(StrandsClientFactory::class, $factory);

        // Verify middleware was passed by checking the factory's private property
        $reflection = new \ReflectionProperty(StrandsClientFactory::class, 'middleware');
        $reflection->setAccessible(true);
        $middleware = $reflection->getValue($factory);

        $this->assertCount(1, $middleware);
        $this->assertSame($mw, $middleware[0]);
    }

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
     * } $strandsConfig
     * @param list<RequestMiddleware> $taggedMiddleware
     */
    private function createRegisteredApplication(array $strandsConfig, array $taggedMiddleware = []): Application
    {
        /** @var array<string, mixed> $configState */
        $configState = ['strands' => $strandsConfig];

        /** @var array<string, callable(Application): mixed> $bindings */
        $bindings = [];

        /** @var array<string, mixed> $instances */
        $instances = [];

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnCallback(
            function ($key, $default = null) use (&$configState): mixed {
                if (!is_string($key) || $key === '') {
                    return $default;
                }

                return $this->getNestedConfigValue($configState, $key, $default);
            },
        );
        $config->method('set')->willReturnCallback(function ($key, $value = null) use (&$configState): void {
            if (is_array($key)) {
                foreach ($key as $nestedKey => $nestedValue) {
                    if (is_string($nestedKey) && $nestedKey !== '') {
                        $this->setNestedConfigValue($configState, $nestedKey, $nestedValue);
                    }
                }

                return;
            }

            if (is_string($key) && $key !== '') {
                $this->setNestedConfigValue($configState, $key, $value);
            }
        });

        $app = $this->createMock(Application::class);
        $app->method('tagged')->willReturnCallback(
            function (string $tag) use ($taggedMiddleware): iterable {
                if ($tag === 'strands.middleware') {
                    return $taggedMiddleware;
                }

                return [];
            },
        );
        $app->method('singleton')->willReturnCallback(
            function ($abstract, $concrete = null) use (&$bindings, $app): Application {
                if (!is_string($abstract) || !is_callable($concrete)) {
                    throw new \RuntimeException('Invalid singleton binding in test harness.');
                }

                $bindings[$abstract] = $concrete;

                return $app;
            },
        );
        $app->method('make')->willReturnCallback(
            function ($abstract) use (&$bindings, &$instances, $config, $app): mixed {
                if ($abstract === 'config') {
                    return $config;
                }

                if ($abstract === LoggerInterface::class) {
                    return new NullLogger();
                }

                if (!is_string($abstract) || $abstract === '') {
                    throw new \RuntimeException('Invalid abstract requested in test harness.');
                }

                if (array_key_exists($abstract, $instances)) {
                    return $instances[$abstract];
                }

                if (!array_key_exists($abstract, $bindings)) {
                    throw new \RuntimeException(sprintf('No binding found for "%s".', $abstract));
                }

                $instances[$abstract] = $bindings[$abstract]($app);

                return $instances[$abstract];
            },
        );

        $provider = new StrandsServiceProvider($app);
        $provider->register();

        return $app;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getNestedConfigValue(array $config, string $path, mixed $default = null): mixed
    {
        $value = $config;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setNestedConfigValue(array &$config, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);

        if ($last === null || $last === '') {
            return;
        }

        $node = &$config;

        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            /** @var array<string, mixed> $node */
            $node = &$node[$segment];
        }

        $node[$last] = $value;
    }

    private function extractEndpoint(StrandsClient $client): string
    {
        $reflection = new \ReflectionProperty(StrandsClient::class, 'config');
        $reflection->setAccessible(true);

        $config = $reflection->getValue($client);
        $this->assertInstanceOf(StrandsConfig::class, $config);

        return $config->endpoint;
    }
}

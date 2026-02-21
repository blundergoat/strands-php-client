<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Integration\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

class StrandsClientFactoryTest extends TestCase
{
    public function testCreateReturnsClient(): void
    {
        $factory = new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $client = $factory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    public function testCreateThrowsForUnknownAgent(): void
    {
        $factory = new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Strands agent "nonexistent"');

        $factory->create('nonexistent');
    }

    public function testCreateThrowsForUnsupportedAuthDriver(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'sigv4'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported auth driver "sigv4"');

        $factory->create('test');
    }

    public function testCreateWithApiKeyAuth(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test-123',
                ],
                'timeout' => 120,
            ],
        ]);

        $client = $factory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    public function testCreateWithApiKeyAuthThrowsWhenMissingKey(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'api_key'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $factory->create('test');
    }

    public function testCreateWithEmptyApiKeyThrows(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => '',
                ],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $factory->create('test');
    }

    public function testCreateWithApiKeyAuthCustomHeader(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test',
                    'header_name' => 'X-API-Key',
                    'value_prefix' => '',
                ],
                'timeout' => 120,
            ],
        ]);

        $client = $factory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    public function testCreateWithRetryConfig(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 60,
                'connect_timeout' => 5,
                'max_retries' => 3,
                'retry_delay_ms' => 1000,
            ],
        ]);

        $client = $factory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    public function testCreateUsesDefaultsWhenRetryFieldsMissing(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $client = $factory->create('test');
        $config = $this->extractConfig($client);

        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame(0, $config->maxRetries);
        $this->assertSame(500, $config->retryDelayMs);
        $this->assertSame([429, 502, 503, 504], $config->retryableStatusCodes);
    }

    public function testCreatePropagatesExplicitConfigValues(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 60,
                'connect_timeout' => 5,
                'max_retries' => 3,
                'retry_delay_ms' => 1000,
                'retryable_status_codes' => [429, 500],
            ],
        ]);

        $client = $factory->create('test');
        $config = $this->extractConfig($client);

        $this->assertSame(60, $config->timeout);
        $this->assertSame(5, $config->connectTimeout);
        $this->assertSame(3, $config->maxRetries);
        $this->assertSame(1000, $config->retryDelayMs);
        $this->assertSame([429, 500], $config->retryableStatusCodes);
    }

    public function testUnknownAgentListsConfiguredAgents(): void
    {
        $factory = new StrandsClientFactory([
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
        ]);

        try {
            $factory->create('missing');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('analyst', $e->getMessage());
            $this->assertStringContainsString('skeptic', $e->getMessage());
        }
    }

    private function extractConfig(StrandsClient $client): StrandsConfig
    {
        $reflection = new \ReflectionProperty(StrandsClient::class, 'config');
        $reflection->setAccessible(true);

        $config = $reflection->getValue($client);
        $this->assertInstanceOf(StrandsConfig::class, $config);

        return $config;
    }
}

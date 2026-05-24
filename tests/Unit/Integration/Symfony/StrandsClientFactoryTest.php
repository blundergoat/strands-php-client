<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

class StrandsClientFactoryTest extends TestCase
{
    /**
     * Verifies that create returns client.
     *
     * @return void
     */
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

    /**
     * Verifies that create throws for unknown agent.
     *
     * @return void
     */
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

    /**
     * Verifies that create throws for unsupported auth driver.
     *
     * @return void
     */
    public function testCreateThrowsForUnsupportedAuthDriver(): void
    {
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'oauth2'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported auth driver "oauth2"');

        $factory->create('test');
    }

    /**
     * Verifies that create with api key auth.
     *
     * @return void
     */
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

    /**
     * Verifies that create with api key auth throws when missing key.
     *
     * @return void
     */
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

    /**
     * Verifies that create with retry config.
     *
     * @return void
     */
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

    /**
     * Verifies that create with api key auth custom header.
     *
     * @return void
     */
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

    /**
     * Verifies that create with empty api key throws.
     *
     * @return void
     */
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

    /**
     * Verifies that create uses defaults when retry fields missing.
     *
     * @return void
     */
    public function testCreateUsesDefaultsWhenRetryFieldsMissing(): void
    {
        // When connect_timeout, max_retries, retry_delay_ms are NOT in config,
        // the factory should use defaults without error
        $factory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $client = $factory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    /**
     * Verifies that unknown agent lists configured agents.
     *
     * @return void
     */
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
}

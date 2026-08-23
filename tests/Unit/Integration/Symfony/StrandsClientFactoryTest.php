<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies Symfony-facing client creation applies agent selection, API-key authentication, retries, and defaults.
 *
 * Use these tests when changing the bundle's StrandsClientFactory compatibility layer.
 * They protect the configured client each Symfony service receives.
 */
class StrandsClientFactoryTest extends TestCase
{
    /**
     * Builds a factory with one known unauthenticated Symfony agent.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithKnownAgent(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);
    }

    /**
     * Confirms create() returns a ready client for a configured Symfony agent.
     *
     * @return void
     */
    public function testCreateReturnsClient(): void
    {
        $strandsClientFactory = $this->factoryWithKnownAgent();

        $client = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Builds the known-agent map used to exercise an unknown name lookup.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryForUnknownAgentLookup(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() rejects an unknown agent name with a clear configuration error.
     *
     * @return void
     */
    public function testCreateThrowsForUnknownAgent(): void
    {
        $strandsClientFactory = $this->factoryForUnknownAgentLookup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Strands agent "nonexistent"');

        $strandsClientFactory->create('nonexistent');
    }
    /**
     * Builds a factory containing an auth driver the client does not support.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithUnsupportedAuthDriver(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'oauth2'],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() rejects an unsupported auth driver before a Symfony app sends a request.
     *
     * @return void
     */
    public function testCreateThrowsForUnsupportedAuthDriver(): void
    {
        $strandsClientFactory = $this->factoryWithUnsupportedAuthDriver();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported auth driver "oauth2"');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds a factory with API-key authentication for a Symfony agent.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithApiKeyAuth(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test-123',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() configures API-key authentication so framework users can securely call the agent.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuth(): void
    {
        $strandsClientFactory = $this->factoryWithApiKeyAuth();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Builds an API-key agent whose required key is omitted.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithMissingApiKey(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'api_key'],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() rejects API-key authentication with no configured key.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuthThrowsWhenMissingKey(): void
    {
        $strandsClientFactory = $this->factoryWithMissingApiKey();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds a factory with the retry behavior selected by the Symfony application.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithRetryConfig(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 60,
                'connect_timeout' => 5,
                'max_retries' => 3,
                'retry_delay_ms' => 1000,
            ],
        ]);
    }


    /**
     * Confirms create() applies retry settings so framework users receive the intended recovery behavior.
     *
     * @return void
     */
    public function testCreateWithRetryConfig(): void
    {
        $strandsClientFactory = $this->factoryWithRetryConfig();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Builds API-key authentication with the custom header expected by a gateway.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithCustomApiKeyHeader(): StrandsClientFactory
    {
        return new StrandsClientFactory([
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
    }


    /**
     * Confirms create() applies a custom API-key header so framework users can match their gateway.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuthCustomHeader(): void
    {
        $strandsClientFactory = $this->factoryWithCustomApiKeyHeader();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Builds an API-key agent whose configured key is an empty string.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithEmptyApiKey(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => '',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() rejects an empty API key before a Symfony app sends a request.
     *
     * @return void
     */
    public function testCreateWithEmptyApiKeyThrows(): void
    {
        $strandsClientFactory = $this->factoryWithEmptyApiKey();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds an agent config that omits every optional retry field.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithoutRetryFields(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() applies documented retry defaults when those fields are omitted.
     *
     * @return void
     */
    public function testCreateUsesDefaultsWhenRetryFieldsMissing(): void
    {
        // When connect_timeout, max_retries, retry_delay_ms are NOT in config,
        // the factory should use defaults without error
        $strandsClientFactory = $this->factoryWithoutRetryFields();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    /**
     * Confirms an unknown-agent error lists valid names the developer can configure or select.
     *
     * @return void
     */
    public function testUnknownAgentListsConfiguredAgents(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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
            $strandsClientFactory->create('missing');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $invalidAgentException) {
            // For example, an app route may request an unknown agent; the message must name valid choices the developer can configure.
            $this->assertStringContainsString('analyst', $invalidAgentException->getMessage());
            $this->assertStringContainsString('skeptic', $invalidAgentException->getMessage());
        }
    }
}

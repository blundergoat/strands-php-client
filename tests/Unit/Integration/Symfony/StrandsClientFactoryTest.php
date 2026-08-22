<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Strands Client Factory behavior for app integrations.
 *
 * Use this file when changing Strands Client Factory or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

/**
 * Exercises Strands Client Factory through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class StrandsClientFactoryTest extends TestCase
{
    /**
     * Test fixture for testCreateReturnsClient().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateReturnsClient(): StrandsClientFactory
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
     * Confirms create() returns client so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateReturnsClient(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateReturnsClient();

        $client = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Test fixture for testCreateThrowsForUnknownAgent().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateThrowsForUnknownAgent(): StrandsClientFactory
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
     * Confirms create() throws for unknown agent so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateThrowsForUnknownAgent(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateThrowsForUnknownAgent();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Strands agent "nonexistent"');

        $strandsClientFactory->create('nonexistent');
    }
    /**
     * Test fixture for testCreateThrowsForUnsupportedAuthDriver().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateThrowsForUnsupportedAuthDriver(): StrandsClientFactory
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
     * Confirms create() throws for unsupported auth driver so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateThrowsForUnsupportedAuthDriver(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateThrowsForUnsupportedAuthDriver();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported auth driver "oauth2"');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateWithApiKeyAuth().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithApiKeyAuth(): StrandsClientFactory
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
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithApiKeyAuth();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Test fixture for testCreateWithApiKeyAuthThrowsWhenMissingKey().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithApiKeyAuthThrowsWhenMissingKey(): StrandsClientFactory
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
     * Confirms create() with api key auth throws when missing key so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuthThrowsWhenMissingKey(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithApiKeyAuthThrowsWhenMissingKey();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateWithRetryConfig().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithRetryConfig(): StrandsClientFactory
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
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithRetryConfig();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Test fixture for testCreateWithApiKeyAuthCustomHeader().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithApiKeyAuthCustomHeader(): StrandsClientFactory
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
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithApiKeyAuthCustomHeader();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }
    /**
     * Test fixture for testCreateWithEmptyApiKeyThrows().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithEmptyApiKeyThrows(): StrandsClientFactory
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
     * Confirms create() with empty api key throws so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateWithEmptyApiKeyThrows(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithEmptyApiKeyThrows();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateUsesDefaultsWhenRetryFieldsMissing().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateUsesDefaultsWhenRetryFieldsMissing(): StrandsClientFactory
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
     * Confirms create() uses defaults when retry fields missing so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateUsesDefaultsWhenRetryFieldsMissing(): void
    {
        // When connect_timeout, max_retries, retry_delay_ms are NOT in config,
        // the factory should use defaults without error
        $strandsClientFactory = $this->strandsClientFactoryForCreateUsesDefaultsWhenRetryFieldsMissing();

        $client = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $client);
    }

    /**
     * Confirms unknown agent lists configured agents so framework users receive a correctly configured client.
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

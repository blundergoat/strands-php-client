<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

class StrandsClientFactoryTest extends TestCase
{
    /**
     * Test fixture for testCreateReturnsClient().
     *
     * @return StrandsClientFactory
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
     * Verifies that create returns client.
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
     * @return StrandsClientFactory
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
     * Verifies that create throws for unknown agent.
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
     * @return StrandsClientFactory
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
     * Verifies that create throws for unsupported auth driver.
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
     * @return StrandsClientFactory
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
     * Verifies that create with api key auth.
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
     * @return StrandsClientFactory
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
     * Verifies that create with api key auth throws when missing key.
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
     * @return StrandsClientFactory
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
     * Verifies that create with retry config.
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
     * @return StrandsClientFactory
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
     * Verifies that create with api key auth custom header.
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
     * @return StrandsClientFactory
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
     * Verifies that create with empty api key throws.
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
     * @return StrandsClientFactory
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
     * Verifies that create uses defaults when retry fields missing.
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
     * Verifies that unknown agent lists configured agents.
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
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('analyst', $e->getMessage());
            $this->assertStringContainsString('skeptic', $e->getMessage());
        }
    }
}

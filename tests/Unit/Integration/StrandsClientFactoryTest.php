<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Strands Client Factory behavior for app integrations.
 *
 * Use this file when changing Strands Client Factory or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Integration\StrandsClientFactory;
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

        $strandsClient = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Confirms create() uses defaults when retry fields missing so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateUsesDefaultsWhenRetryFieldsMissing(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $strandsClient = $strandsClientFactory->create('test');
        $config = $this->extractConfig($strandsClient);

        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame(0, $config->maxRetries);
        $this->assertSame(500, $config->retryDelayMs);
        $this->assertSame([429, 502, 503, 504], $config->retryableStatusCodes);
    }

    /**
     * Confirms create() propagates explicit config values so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreatePropagatesExplicitConfigValues(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClient = $strandsClientFactory->create('test');
        $config = $this->extractConfig($strandsClient);

        $this->assertSame(60, $config->timeout);
        $this->assertSame(5, $config->connectTimeout);
        $this->assertSame(3, $config->maxRetries);
        $this->assertSame(1000, $config->retryDelayMs);
        $this->assertSame([429, 500], $config->retryableStatusCodes);
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
    /**
     * Test fixture for testCreateWithSigv4Auth().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithSigv4Auth(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'access_key_id' => 'AKID',
                    'secret_access_key' => 'SECRET',
                    'region' => 'us-east-1',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() configures SigV4 authentication so framework users can securely call an AWS gateway.
     *
     * @return void
     */
    public function testCreateWithSigv4Auth(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithSigv4Auth();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Test fixture for testCreateWithApiKeyCustomHeaderAndPrefix().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithApiKeyCustomHeaderAndPrefix(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test',
                    'header_name' => 'X-Custom-Key',
                    'value_prefix' => 'Token ',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() applies a custom API-key header and prefix so framework users can match their gateway.
     *
     * @return void
     */
    public function testCreateWithApiKeyCustomHeaderAndPrefix(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithApiKeyCustomHeaderAndPrefix();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Test fixture for testCreateWithApiKeyDefaultHeaderAndPrefix().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateWithApiKeyDefaultHeaderAndPrefix(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() supplies the default API-key header and prefix so framework users need only provide the key.
     *
     * @return void
     */
    public function testCreateWithApiKeyDefaultHeaderAndPrefix(): void
    {
        // When header_name and value_prefix are not provided, defaults should apply
        $strandsClientFactory = $this->strandsClientFactoryForCreateWithApiKeyDefaultHeaderAndPrefix();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Confirms create() accepts traversable middleware so framework users can register an iterable pipeline.
     *
     * @return void
     */
    public function testCreateWithTraversableMiddleware(): void
    {
        $requestMiddleware = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            /**
             * Return request headers and body from the middleware test stub.
             *
             * @param string $url Request URL being observed.
             * @param array<string, string> $headers Request headers supplied to the
             * middleware stub.
             * @param string $body Request body supplied to the middleware stub.
             * @return array{headers: array<string, string>, body: string} Non-empty request map; its header map may be empty.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                return ['headers' => $headers, 'body' => $body];
            }

            /**
             * Handle after-response middleware calls for the test stub.
             *
             * @param string $url Request URL being observed.
             * @param int $statusCode HTTP status code for the operation.
             * @param float $durationMs Operation duration in milliseconds.
             * @param \Throwable|null $error Request failure; null means the user's call completed successfully.
             * @return void
             */
            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
            }
        };

        // A traversable middleware list mirrors the tagged iterator Symfony supplies to app services.
        $middlewareIterator = new \ArrayIterator([$requestMiddleware]);

        $strandsClientFactory = new StrandsClientFactory(
            [
                'test' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
            ],
            middleware: $middlewareIterator,
        );

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Test fixture for testCreateSigv4ThrowsWhenMissingRegion().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateSigv4ThrowsWhenMissingRegion(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'access_key_id' => 'AKID',
                    'secret_access_key' => 'SECRET',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() sigv 4 throws when missing region so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsWhenMissingRegion(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateSigv4ThrowsWhenMissingRegion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('region" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateSigv4ThrowsOnPartialCredentials().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateSigv4ThrowsOnPartialCredentials(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'region' => 'us-east-1',
                    'access_key_id' => 'AKID',
                    // secret_access_key intentionally omitted
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() sigv 4 throws on partial credentials so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentials(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateSigv4ThrowsOnPartialCredentials();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateSigv4ThrowsOnPartialCredentialsReverse().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateSigv4ThrowsOnPartialCredentialsReverse(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'region' => 'us-east-1',
                    'secret_access_key' => 'SECRET',
                    // access_key_id intentionally omitted
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() sigv 4 throws on partial credentials reverse so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentialsReverse(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateSigv4ThrowsOnPartialCredentialsReverse();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }
    /**
     * Test fixture for testCreateSigv4WithSessionToken().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateSigv4WithSessionToken(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'region' => 'us-east-1',
                    'access_key_id' => 'AKID',
                    'secret_access_key' => 'SECRET',
                    'session_token' => 'TOKEN',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() passes a SigV4 session token so framework users can use temporary AWS credentials.
     *
     * @return void
     */
    public function testCreateSigv4WithSessionToken(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateSigv4WithSessionToken();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Test fixture for testCreateSigv4WithCustomService().
     *
     * @return StrandsClientFactory Value returned to app code.
     */
    private function strandsClientFactoryForCreateSigv4WithCustomService(): StrandsClientFactory
    {
        return new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'sigv4',
                    'region' => 'us-east-1',
                    'service' => 'lambda',
                    'access_key_id' => 'AKID',
                    'secret_access_key' => 'SECRET',
                ],
                'timeout' => 120,
            ],
        ]);
    }


    /**
     * Confirms create() passes a custom SigV4 service so framework users can target the intended AWS endpoint.
     *
     * @return void
     */
    public function testCreateSigv4WithCustomService(): void
    {
        $strandsClientFactory = $this->strandsClientFactoryForCreateSigv4WithCustomService();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Extract config for assertions.
     *
     * @param StrandsClient $strandsClient Client instance inspected by the test helper.
     * @return StrandsConfig Value produced by the method.
     */
    private function extractConfig(StrandsClient $strandsClient): StrandsConfig
    {
        $reflectionProperty = new \ReflectionProperty(StrandsClient::class, 'config');
        $config = $reflectionProperty->getValue($strandsClient);
        $this->assertInstanceOf(StrandsConfig::class, $config);

        return $config;
    }
}

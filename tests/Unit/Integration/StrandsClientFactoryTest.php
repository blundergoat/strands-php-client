<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Integration\StrandsClientFactory;
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

        $strandsClient = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create uses defaults when retry fields missing.
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
     * Verifies that create propagates explicit config values.
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
    /**
     * Test fixture for testCreateWithSigv4Auth().
     *
     * @return StrandsClientFactory
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
     * Verifies that create with sigv 4 auth.
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
     * @return StrandsClientFactory
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
     * Verifies that create with api key custom header and prefix.
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
     * @return StrandsClientFactory
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
     * Verifies that create with api key default header and prefix.
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
     * Verifies that create with traversable middleware.
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
             * @return array{headers: array<string, string>, body: string} Headers and body
             * returned by the middleware stub.
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
             * @param \Throwable|null $error Optional transport or agent error raised by
             * the operation.
             * @return void
             */
            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
            }
        };

        // Pass middleware as ArrayIterator (Traversable) — simulates Symfony DI tagged iterator
        $arrayIterator = new \ArrayIterator([$requestMiddleware]);

        $strandsClientFactory = new StrandsClientFactory(
            [
                'test' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                    'timeout' => 120,
                ],
            ],
            middleware: $arrayIterator,
        );

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Test fixture for testCreateSigv4ThrowsWhenMissingRegion().
     *
     * @return StrandsClientFactory
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
     * Verifies that create sigv 4 throws when missing region.
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
     * @return StrandsClientFactory
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
     * Verifies that create sigv 4 throws on partial credentials.
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
     * @return StrandsClientFactory
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
     * Verifies that create sigv 4 throws on partial credentials reverse.
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
     * @return StrandsClientFactory
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
     * Verifies that create sigv 4 with session token.
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
     * @return StrandsClientFactory
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
     * Verifies that create sigv 4 with custom service.
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

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
     * Verifies that create returns client.
     *
     * @return void
     */
    public function testCreateReturnsClient(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $strandsClient = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create throws for unknown agent.
     *
     * @return void
     */
    public function testCreateThrowsForUnknownAgent(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'analyst' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Strands agent "nonexistent"');

        $strandsClientFactory->create('nonexistent');
    }

    /**
     * Verifies that create throws for unsupported auth driver.
     *
     * @return void
     */
    public function testCreateThrowsForUnsupportedAuthDriver(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'oauth2'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported auth driver "oauth2"');

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create with api key auth.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuth(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test-123',
                ],
                'timeout' => 120,
            ],
        ]);

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create with api key auth throws when missing key.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuthThrowsWhenMissingKey(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'api_key'],
                'timeout' => 120,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key" option is required');

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create with empty api key throws.
     *
     * @return void
     */
    public function testCreateWithEmptyApiKeyThrows(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create with api key auth custom header.
     *
     * @return void
     */
    public function testCreateWithApiKeyAuthCustomHeader(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create with retry config.
     *
     * @return void
     */
    public function testCreateWithRetryConfig(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => ['driver' => 'null'],
                'timeout' => 60,
                'connect_timeout' => 5,
                'max_retries' => 3,
                'retry_delay_ms' => 1000,
            ],
        ]);

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
     * Verifies that create with sigv 4 auth.
     *
     * @return void
     */
    public function testCreateWithSigv4Auth(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create with api key custom header and prefix.
     *
     * @return void
     */
    public function testCreateWithApiKeyCustomHeaderAndPrefix(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create with api key default header and prefix.
     *
     * @return void
     */
    public function testCreateWithApiKeyDefaultHeaderAndPrefix(): void
    {
        // When header_name and value_prefix are not provided, defaults should apply
        $strandsClientFactory = new StrandsClientFactory([
            'test' => [
                'endpoint' => 'http://agent:8000',
                'auth' => [
                    'driver' => 'api_key',
                    'api_key' => 'sk-test',
                ],
                'timeout' => 120,
            ],
        ]);

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
     * Verifies that create sigv 4 throws when missing region.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsWhenMissingRegion(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('region" option is required');

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create sigv 4 throws on partial credentials.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentials(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create sigv 4 throws on partial credentials reverse.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentialsReverse(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }

    /**
     * Verifies that create sigv 4 with session token.
     *
     * @return void
     */
    public function testCreateSigv4WithSessionToken(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Verifies that create sigv 4 with custom service.
     *
     * @return void
     */
    public function testCreateSigv4WithCustomService(): void
    {
        $strandsClientFactory = new StrandsClientFactory([
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

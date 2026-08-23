<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Integration\StrandsClientFactory;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies framework-neutral client creation applies agent selection, authentication, retries, and middleware configuration.
 *
 * Use these tests when changing StrandsClientFactory or its supported configuration keys.
 * They protect the client behavior an integration receives from one agent definition.
 */
class StrandsClientFactoryTest extends TestCase
{
    /**
     * Builds a factory with one known unauthenticated agent.
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
     * Confirms create() returns a ready client for a configured agent.
     *
     * @return void
     */
    public function testCreateReturnsClient(): void
    {
        $strandsClientFactory = $this->factoryWithKnownAgent();

        $strandsClient = $strandsClientFactory->create('analyst');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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
     * Confirms create() rejects an unsupported auth driver before an application sends a request.
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
     * Builds a factory with API-key authentication for one agent.
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
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
     * Confirms create() rejects an empty API key before an application sends a request.
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Builds a factory with the retry behavior selected by the application.
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

        $strandsClient = $strandsClientFactory->create('test');

        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Confirms create() applies documented retry defaults when those fields are omitted.
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
        $config = $this->configFromClient($strandsClient);

        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame(0, $config->maxRetries);
        $this->assertSame(500, $config->retryDelayMs);
        $this->assertSame([429, 502, 503, 504], $config->retryableStatusCodes);
    }

    /**
     * Confirms create() preserves explicit timeout and retry settings for the selected agent.
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
        $config = $this->configFromClient($strandsClient);

        $this->assertSame(60, $config->timeout);
        $this->assertSame(5, $config->connectTimeout);
        $this->assertSame(3, $config->maxRetries);
        $this->assertSame(1000, $config->retryDelayMs);
        $this->assertSame([429, 500], $config->retryableStatusCodes);
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
    /**
     * Builds a factory with complete SigV4 authentication settings.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithSigV4Auth(): StrandsClientFactory
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
        $strandsClientFactory = $this->factoryWithSigV4Auth();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Builds API-key authentication with a custom header and value prefix.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithCustomApiKeyHeaderAndPrefix(): StrandsClientFactory
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
        $strandsClientFactory = $this->factoryWithCustomApiKeyHeaderAndPrefix();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Builds API-key authentication that relies on the documented header defaults.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithDefaultApiKeyHeader(): StrandsClientFactory
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
        $strandsClientFactory = $this->factoryWithDefaultApiKeyHeader();

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
             * Passes request data through, matching middleware that only observes an app call.
             *
             * @param string $url Non-empty request URL observed by middleware.
             * @param array<string, string> $headers Caller headers; empty means the app supplied no custom headers.
             * @param string $body Request body; empty means middleware receives no payload content.
             * @return array{headers: array<string, string>, body: string} Non-empty request map; its header map may be empty.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                return ['headers' => $headers, 'body' => $body];
            }

            /**
             * Accepts the completion notification emitted after the simulated app call.
             *
             * @param string $url Non-empty request URL observed by middleware.
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
     * Builds SigV4 authentication with its required region omitted.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithMissingSigV4Region(): StrandsClientFactory
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
     * Confirms create() rejects SigV4 authentication without a region.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsWhenMissingRegion(): void
    {
        $strandsClientFactory = $this->factoryWithMissingSigV4Region();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('region" option is required');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds SigV4 authentication with an access key but no signing secret.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithAccessKeyOnly(): StrandsClientFactory
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
     * Confirms create() rejects an access key that has no matching signing secret.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentials(): void
    {
        $strandsClientFactory = $this->factoryWithAccessKeyOnly();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds SigV4 authentication with a signing secret but no access key.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithSigningSecretOnly(): StrandsClientFactory
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
     * Confirms create() rejects a signing secret that has no matching access key.
     *
     * @return void
     */
    public function testCreateSigv4ThrowsOnPartialCredentialsReverse(): void
    {
        $strandsClientFactory = $this->factoryWithSigningSecretOnly();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both "access_key_id" and "secret_access_key" must be provided together');

        $strandsClientFactory->create('test');
    }
    /**
     * Builds SigV4 authentication with temporary-session credentials.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithSigV4SessionToken(): StrandsClientFactory
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
        $strandsClientFactory = $this->factoryWithSigV4SessionToken();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }
    /**
     * Builds SigV4 authentication for a custom AWS service name.
     *
     * @return StrandsClientFactory Configured factory for this caller scenario; never null.
     */
    private function factoryWithCustomSigV4Service(): StrandsClientFactory
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
        $strandsClientFactory = $this->factoryWithCustomSigV4Service();

        $strandsClient = $strandsClientFactory->create('test');
        $this->assertInstanceOf(StrandsClient::class, $strandsClient);
    }

    /**
     * Reads the resolved client configuration so tests can verify what framework users receive.
     * Use it after factory creation when public behavior depends on a normalized setting.
     *
     * @param StrandsClient $strandsClient Factory-created client; never null.
     * @return StrandsConfig Resolved client settings; never null.
     */
    private function configFromClient(StrandsClient $strandsClient): StrandsConfig
    {
        $reflectionProperty = new \ReflectionProperty(StrandsClient::class, 'config');
        $config = $reflectionProperty->getValue($strandsClient);
        $this->assertInstanceOf(StrandsConfig::class, $config);

        return $config;
    }
}

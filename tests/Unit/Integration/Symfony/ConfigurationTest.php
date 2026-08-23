<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

/**
 * Verifies Symfony accepts documented Strands settings, fills defaults, and rejects invalid agent definitions clearly.
 *
 * Use these tests when changing the bundle configuration tree or normalized values.
 * They protect the settings application services receive after container compilation.
 */
class ConfigurationTest extends TestCase
{
    /**
     * Normalizes one application config map through Symfony's Strands configuration tree.
     * Use it to inspect the defaults and validation result services receive at runtime.
     *
     * @param array<string, mixed> $config Application settings; empty means no explicit Strands values.
     * @return array<string, mixed> Normalized settings; never empty because the tree supplies defaults.
     */
    private function processSymfonyConfig(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$config]);
    }
    /**
     * Builds an endpoint-only agent config so Symfony can supply the documented defaults.
     *
     * @return array<string, mixed> Non-empty agent config with no optional overrides.
     */
    private function minimalAgentConfig(): array
    {
        return [
            'agents' => [
                'analyst' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ];
    }


    /**
     * Confirms minimal configuration receives safe defaults so framework users can create a client with only an endpoint.
     *
     * @return void
     */
    public function testMinimalConfig(): void
    {
        $config = $this->processSymfonyConfig($this->minimalAgentConfig());

        $this->assertArrayHasKey('analyst', $config['agents']);
        $this->assertSame('http://agent:8000', $config['agents']['analyst']['endpoint']);
        $this->assertSame('null', $config['agents']['analyst']['auth']['driver']);
        $this->assertSame(120, $config['agents']['analyst']['timeout']);
    }
    /**
     * Builds three named agents that application services can select independently.
     *
     * @return array<string, mixed> Non-empty config containing three agent definitions.
     */
    private function multipleAgentConfig(): array
    {
        return [
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
                'strategist' => ['endpoint' => 'http://agent:8000'],
            ],
        ];
    }


    /**
     * Confirms multiple named agents remain available so framework users can select the right assistant.
     *
     * @return void
     */
    public function testMultipleAgents(): void
    {
        $config = $this->processSymfonyConfig($this->multipleAgentConfig());

        $this->assertCount(3, $config['agents']);
    }
    /**
     * Builds an agent config whose request timeout overrides the bundle default.
     *
     * @return array<string, mixed> Non-empty config with an explicit timeout.
     */
    private function customTimeoutConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'timeout' => 60,
                ],
            ],
        ];
    }


    /**
     * Confirms a custom timeout replaces the default so framework users control how long a request may wait.
     *
     * @return void
     */
    public function testCustomTimeout(): void
    {
        $config = $this->processSymfonyConfig($this->customTimeoutConfig());

        $this->assertSame(60, $config['agents']['primary']['timeout']);
    }
    /**
     * Builds an agent config with no auth driver so Symfony must choose the safe default.
     *
     * @return array<string, mixed> Non-empty config whose auth section is omitted.
     */
    private function configWithoutAuthDriver(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ];
    }


    /**
     * Confirms authentication defaults to none so framework users can call an open gateway.
     *
     * @return void
     */
    public function testAuthDriverDefault(): void
    {
        $config = $this->processSymfonyConfig($this->configWithoutAuthDriver());

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }
    /**
     * Builds an agent config that explicitly selects unauthenticated requests.
     *
     * @return array<string, mixed> Non-empty config with the null auth driver selected.
     */
    private function nullAuthConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                ],
            ],
        ];
    }


    /**
     * Confirms explicit null authentication remains valid so framework users can document an open gateway.
     *
     * @return void
     */
    public function testExplicitNullAuth(): void
    {
        $config = $this->processSymfonyConfig($this->nullAuthConfig());

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }
    /**
     * Builds API-key auth with only the credential so Symfony must fill header defaults.
     *
     * @return array<string, mixed> Non-empty config with API-key authentication enabled.
     */
    private function apiKeyAuthConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => [
                        'driver' => 'api_key',
                        'api_key' => 'sk-test-123',
                    ],
                ],
            ],
        ];
    }


    /**
     * Confirms API-key authentication keeps its defaults so framework users can securely call the agent.
     *
     * @return void
     */
    public function testApiKeyAuthDriver(): void
    {
        $config = $this->processSymfonyConfig($this->apiKeyAuthConfig());

        $this->assertSame('api_key', $config['agents']['primary']['auth']['driver']);
        $this->assertSame('sk-test-123', $config['agents']['primary']['auth']['api_key']);
        $this->assertSame('Authorization', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('Bearer ', $config['agents']['primary']['auth']['value_prefix']);
    }
    /**
     * Builds API-key auth with the custom header an application gateway expects.
     *
     * @return array<string, mixed> Non-empty config with an empty prefix and custom header.
     */
    private function customApiKeyHeaderConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => [
                        'driver' => 'api_key',
                        'api_key' => 'key-abc',
                        'header_name' => 'X-API-Key',
                        'value_prefix' => '',
                    ],
                ],
            ],
        ];
    }


    /**
     * Confirms API-key authentication accepts a custom header so framework users can match their gateway.
     *
     * @return void
     */
    public function testApiKeyAuthWithCustomHeader(): void
    {
        $config = $this->processSymfonyConfig($this->customApiKeyHeaderConfig());

        $this->assertSame('X-API-Key', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('', $config['agents']['primary']['auth']['value_prefix']);
    }

    /**
     * Each invalid-agent-config case must surface an InvalidConfigurationException whose
     * message identifies the offending field so consumers can act on it.
     *
     * @param array<string, mixed> $primaryOverrides Non-empty invalid settings merged into the primary agent.
     * @param string $expectedMessagePattern Non-empty regex identifying the caller-visible configuration error.
     * @return void
     */
    #[DataProvider('invalidAgentConfigProvider')]
    public function testInvalidAgentConfigRejectedWithIdentifyingMessage(array $primaryOverrides, string $expectedMessagePattern): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->processSymfonyConfig([
            'agents' => [
                'primary' => array_merge(['endpoint' => 'http://agent:8000'], $primaryOverrides),
            ],
        ]);
    }

    /**
     * Lists invalid agent settings and the configuration guidance Symfony must expose.
     * An empty provider would leave one application-startup failure unverified.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}> Invalid overrides and expected messages; never empty.
     */
    public static function invalidAgentConfigProvider(): iterable
    {
        yield 'unsupported auth driver' => [['auth' => ['driver' => 'oauth2']], '/oauth2/'];
        yield 'zero timeout' => [['timeout' => 0], '/timeout/'];
        yield 'negative connect_timeout' => [['connect_timeout' => -1], '/connect_timeout/'];
        yield 'max_retries above upper bound' => [['max_retries' => 21], '/max_retries/'];
        yield 'zero retry_delay_ms' => [['retry_delay_ms' => 0], '/retry_delay_ms/'];
        yield 'negative max_retries' => [['max_retries' => -1], '/max_retries/'];
        yield 'negative retry_delay_ms' => [['retry_delay_ms' => -1], '/retry_delay_ms/'];
    }
    /**
     * Builds an endpoint-only agent config so Symfony must add connection and retry defaults.
     *
     * @return array<string, mixed> Non-empty config with all retry fields omitted.
     */
    private function configWithoutRetryFields(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ];
    }


    /**
     * Confirms retry and connection defaults are added so framework users receive a resilient client.
     *
     * @return void
     */
    public function testNewConfigDefaults(): void
    {
        $config = $this->processSymfonyConfig($this->configWithoutRetryFields());

        $agent = $config['agents']['primary'];
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }
    /**
     * Builds the retry and connection settings an application chose for one agent.
     *
     * @return array<string, mixed> Non-empty config with explicit retry and connection values.
     */
    private function customRetryConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 3,
                    'retry_delay_ms' => 1000,
                    'connect_timeout' => 5,
                ],
            ],
        ];
    }


    /**
     * Confirms custom retry settings replace the defaults so framework users control recovery behavior.
     *
     * @return void
     */
    public function testCustomRetrySettings(): void
    {
        $config = $this->processSymfonyConfig($this->customRetryConfig());

        $agent = $config['agents']['primary'];
        $this->assertSame(3, $agent['max_retries']);
        $this->assertSame(1000, $agent['retry_delay_ms']);
        $this->assertSame(5, $agent['connect_timeout']);
    }

    /**
     * Confirms configuration accepts the maximum retry boundary so Symfony apps receive the documented limits.
     *
     * @return void
     */
    public function testAcceptsBoundaryMaxRetries(): void
    {
        $configZero = $this->processSymfonyConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 0,
                ],
            ],
        ]);
        $this->assertSame(0, $configZero['agents']['primary']['max_retries']);

        $configMax = $this->processSymfonyConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 20,
                ],
            ],
        ]);
        $this->assertSame(20, $configMax['agents']['primary']['max_retries']);
    }
    /**
     * Builds the minimum accepted timeout values for a caller that wants fast failure.
     *
     * @return array<string, mixed> Non-empty config with each timeout boundary set to one.
     */
    private function minimumTimeoutConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'timeout' => 1,
                    'connect_timeout' => 1,
                    'retry_delay_ms' => 1,
                ],
            ],
        ];
    }


    /**
     * Confirms configuration accepts the documented timeout boundaries so Symfony apps receive the documented limits.
     *
     * @return void
     */
    public function testAcceptsBoundaryTimeouts(): void
    {
        $config = $this->processSymfonyConfig($this->minimumTimeoutConfig());

        $agent = $config['agents']['primary'];
        $this->assertSame(1, $agent['timeout']);
        $this->assertSame(1, $agent['connect_timeout']);
        $this->assertSame(1, $agent['retry_delay_ms']);
    }
    /**
     * Builds an agent config that relies on the documented retryable status codes.
     *
     * @return array<string, mixed> Non-empty config with retryable status codes omitted.
     */
    private function configWithoutRetryableStatusCodes(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ];
    }


    /**
     * Confirms retryable status defaults are added so framework users receive resilient request behavior.
     *
     * @return void
     */
    public function testDefaultRetryableStatusCodes(): void
    {
        $config = $this->processSymfonyConfig($this->configWithoutRetryableStatusCodes());

        $this->assertSame([429, 502, 503, 504], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Builds the HTTP failures an application explicitly chose to retry.
     *
     * @return array<string, mixed> Non-empty config with a custom retryable status-code list.
     */
    private function customRetryableStatusCodesConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retryable_status_codes' => [429, 500, 502, 503],
                ],
            ],
        ];
    }


    /**
     * Confirms custom retryable status codes replace the defaults so framework users control which failures retry.
     *
     * @return void
     */
    public function testCustomRetryableStatusCodes(): void
    {
        $config = $this->processSymfonyConfig($this->customRetryableStatusCodesConfig());

        $this->assertSame([429, 500, 502, 503], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Builds an empty retryable status list for an application that disables status-based retries.
     *
     * @return array<string, mixed> Non-empty config containing an intentionally empty status list.
     */
    private function emptyRetryableStatusCodesConfig(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retryable_status_codes' => [],
                ],
            ],
        ];
    }


    /**
     * Confirms an empty retryable status list is preserved so framework users can disable status-based retries.
     *
     * @return void
     */
    public function testEmptyRetryableStatusCodes(): void
    {
        $config = $this->processSymfonyConfig($this->emptyRetryableStatusCodesConfig());

        $this->assertSame([], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Builds an endpoint-only config used to verify the complete retry default set.
     *
     * @return array<string, mixed> Non-empty config with every retry setting omitted.
     */
    private function endpointOnlyConfigForRetryDefaults(): array
    {
        return [
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ];
    }


    /**
     * Confirms all retry defaults are populated so framework users receive predictable recovery behavior.
     *
     * @return void
     */
    public function testDefaultRetryableFields(): void
    {
        $config = $this->processSymfonyConfig($this->endpointOnlyConfigForRetryDefaults());

        $agent = $config['agents']['primary'];
        $this->assertSame(120, $agent['timeout']);
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }
}

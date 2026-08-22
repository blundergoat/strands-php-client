<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Configuration behavior for app integrations.
 *
 * Use this file when changing Configuration or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

/**
 * Exercises Configuration through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class ConfigurationTest extends TestCase
{
    /**
     * Process config for assertions.
     *
     * @param array<string, mixed> $config Configuration values passed to the helper.
     * @return array<string, mixed> Decoded fixture or processed configuration array.
     */
    private function processConfig(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$config]);
    }
    /**
     * Test fixture for testMinimalConfig().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForMinimalConfig(): array
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
        $config = $this->processConfig($this->dataForMinimalConfig());

        $this->assertArrayHasKey('analyst', $config['agents']);
        $this->assertSame('http://agent:8000', $config['agents']['analyst']['endpoint']);
        $this->assertSame('null', $config['agents']['analyst']['auth']['driver']);
        $this->assertSame(120, $config['agents']['analyst']['timeout']);
    }
    /**
     * Test fixture for testMultipleAgents().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForMultipleAgents(): array
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
        $config = $this->processConfig($this->dataForMultipleAgents());

        $this->assertCount(3, $config['agents']);
    }
    /**
     * Test fixture for testCustomTimeout().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForCustomTimeout(): array
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
        $config = $this->processConfig($this->dataForCustomTimeout());

        $this->assertSame(60, $config['agents']['primary']['timeout']);
    }
    /**
     * Test fixture for testAuthDriverDefault().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForAuthDriverDefault(): array
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
        $config = $this->processConfig($this->dataForAuthDriverDefault());

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }
    /**
     * Test fixture for testExplicitNullAuth().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForExplicitNullAuth(): array
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
        $config = $this->processConfig($this->dataForExplicitNullAuth());

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }
    /**
     * Test fixture for testApiKeyAuthDriver().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForApiKeyAuthDriver(): array
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
        $config = $this->processConfig($this->dataForApiKeyAuthDriver());

        $this->assertSame('api_key', $config['agents']['primary']['auth']['driver']);
        $this->assertSame('sk-test-123', $config['agents']['primary']['auth']['api_key']);
        $this->assertSame('Authorization', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('Bearer ', $config['agents']['primary']['auth']['value_prefix']);
    }
    /**
     * Test fixture for testApiKeyAuthWithCustomHeader().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForApiKeyAuthWithCustomHeader(): array
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
        $config = $this->processConfig($this->dataForApiKeyAuthWithCustomHeader());

        $this->assertSame('X-API-Key', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('', $config['agents']['primary']['auth']['value_prefix']);
    }

    /**
     * Each invalid-agent-config case must surface an InvalidConfigurationException whose
     * message identifies the offending field so consumers can act on it.
     *
     * @param array<string, mixed> $primaryOverrides Overrides merged into the 'primary' agent block.
     * @param string $expectedMessagePattern Regex the exception message must match.
     * @return void
     */
    #[DataProvider('invalidAgentConfigProvider')]
    public function testInvalidAgentConfigRejectedWithIdentifyingMessage(array $primaryOverrides, string $expectedMessagePattern): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->processConfig([
            'agents' => [
                'primary' => array_merge(['endpoint' => 'http://agent:8000'], $primaryOverrides),
            ],
        ]);
    }

    /**
     * Invalid-agent-config cases for testInvalidAgentConfigRejectedWithIdentifyingMessage().
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}> Scenario data for invalid agent config behavior.
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
     * Test fixture for testNewConfigDefaults().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForNewConfigDefaults(): array
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
        $config = $this->processConfig($this->dataForNewConfigDefaults());

        $agent = $config['agents']['primary'];
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }
    /**
     * Test fixture for testCustomRetrySettings().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForCustomRetrySettings(): array
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
        $config = $this->processConfig($this->dataForCustomRetrySettings());

        $agent = $config['agents']['primary'];
        $this->assertSame(3, $agent['max_retries']);
        $this->assertSame(1000, $agent['retry_delay_ms']);
        $this->assertSame(5, $agent['connect_timeout']);
    }

    /**
     * Confirms accepts boundary max retries so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testAcceptsBoundaryMaxRetries(): void
    {
        $configZero = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 0,
                ],
            ],
        ]);
        $this->assertSame(0, $configZero['agents']['primary']['max_retries']);

        $configMax = $this->processConfig([
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
     * Test fixture for testAcceptsBoundaryTimeouts().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForAcceptsBoundaryTimeouts(): array
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
     * Confirms accepts boundary timeouts so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testAcceptsBoundaryTimeouts(): void
    {
        $config = $this->processConfig($this->dataForAcceptsBoundaryTimeouts());

        $agent = $config['agents']['primary'];
        $this->assertSame(1, $agent['timeout']);
        $this->assertSame(1, $agent['connect_timeout']);
        $this->assertSame(1, $agent['retry_delay_ms']);
    }
    /**
     * Test fixture for testDefaultRetryableStatusCodes().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForDefaultRetryableStatusCodes(): array
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
        $config = $this->processConfig($this->dataForDefaultRetryableStatusCodes());

        $this->assertSame([429, 502, 503, 504], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Test fixture for testCustomRetryableStatusCodes().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForCustomRetryableStatusCodes(): array
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
        $config = $this->processConfig($this->dataForCustomRetryableStatusCodes());

        $this->assertSame([429, 500, 502, 503], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Test fixture for testEmptyRetryableStatusCodes().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForEmptyRetryableStatusCodes(): array
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
        $config = $this->processConfig($this->dataForEmptyRetryableStatusCodes());

        $this->assertSame([], $config['agents']['primary']['retryable_status_codes']);
    }
    /**
     * Test fixture for testDefaultRetryableFields().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForDefaultRetryableFields(): array
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
        $config = $this->processConfig($this->dataForDefaultRetryableFields());

        $agent = $config['agents']['primary'];
        $this->assertSame(120, $agent['timeout']);
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

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
     * @return array<string, mixed>
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
     * Verifies that minimal config.
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
     * @return array<string, mixed>
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
     * Verifies that multiple agents.
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
     * @return array<string, mixed>
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
     * Verifies that custom timeout.
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
     * @return array<string, mixed>
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
     * Verifies that auth driver default.
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
     * @return array<string, mixed>
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
     * Verifies that explicit null auth.
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
     * @return array<string, mixed>
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
     * Verifies that api key auth driver.
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
     * @return array<string, mixed>
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
     * Verifies that api key auth with custom header.
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
     * Verifies that rejects unsupported auth driver.
     *
     * @return void
     */
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
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
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
     * @return array<string, mixed>
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
     * Verifies that new config defaults.
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
     * @return array<string, mixed>
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
     * Verifies that custom retry settings.
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
     * Verifies that rejects zero timeout.
     *
     * @return void
     */

    /**
     * Verifies that rejects max retries above 20.
     *
     * @return void
     */

    /**
     * Verifies that accepts boundary max retries.
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
     * @return array<string, mixed>
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
     * Verifies that accepts boundary timeouts.
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
     * @return array<string, mixed>
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
     * Verifies that rejects negative max retries.
     *
     * @return void
     */

    /**
     * Verifies that default retryable status codes.
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
     * @return array<string, mixed>
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
     * Verifies that custom retryable status codes.
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
     * @return array<string, mixed>
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
     * Verifies that empty retryable status codes.
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
     * @return array<string, mixed>
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
     * Verifies that default retryable fields.
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

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

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
     * Verifies that minimal config.
     *
     * @return void
     */
    public function testMinimalConfig(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'analyst' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ]);

        $this->assertArrayHasKey('analyst', $config['agents']);
        $this->assertSame('http://agent:8000', $config['agents']['analyst']['endpoint']);
        $this->assertSame('null', $config['agents']['analyst']['auth']['driver']);
        $this->assertSame(120, $config['agents']['analyst']['timeout']);
    }

    /**
     * Verifies that multiple agents.
     *
     * @return void
     */
    public function testMultipleAgents(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
                'strategist' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertCount(3, $config['agents']);
    }

    /**
     * Verifies that custom timeout.
     *
     * @return void
     */
    public function testCustomTimeout(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'timeout' => 60,
                ],
            ],
        ]);

        $this->assertSame(60, $config['agents']['primary']['timeout']);
    }

    /**
     * Verifies that auth driver default.
     *
     * @return void
     */
    public function testAuthDriverDefault(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ]);

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }

    /**
     * Verifies that explicit null auth.
     *
     * @return void
     */
    public function testExplicitNullAuth(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'null'],
                ],
            ],
        ]);

        $this->assertSame('null', $config['agents']['primary']['auth']['driver']);
    }

    /**
     * Verifies that api key auth driver.
     *
     * @return void
     */
    public function testApiKeyAuthDriver(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => [
                        'driver' => 'api_key',
                        'api_key' => 'sk-test-123',
                    ],
                ],
            ],
        ]);

        $this->assertSame('api_key', $config['agents']['primary']['auth']['driver']);
        $this->assertSame('sk-test-123', $config['agents']['primary']['auth']['api_key']);
        $this->assertSame('Authorization', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('Bearer ', $config['agents']['primary']['auth']['value_prefix']);
    }

    /**
     * Verifies that api key auth with custom header.
     *
     * @return void
     */
    public function testApiKeyAuthWithCustomHeader(): void
    {
        $config = $this->processConfig([
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
        ]);

        $this->assertSame('X-API-Key', $config['agents']['primary']['auth']['header_name']);
        $this->assertSame('', $config['agents']['primary']['auth']['value_prefix']);
    }

    /**
     * Verifies that rejects unsupported auth driver.
     *
     * @return void
     */
    public function testRejectsUnsupportedAuthDriver(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'auth' => ['driver' => 'oauth2'],
                ],
            ],
        ]);
    }

    /**
     * Verifies that new config defaults.
     *
     * @return void
     */
    public function testNewConfigDefaults(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ]);

        $agent = $config['agents']['primary'];
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }

    /**
     * Verifies that custom retry settings.
     *
     * @return void
     */
    public function testCustomRetrySettings(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 3,
                    'retry_delay_ms' => 1000,
                    'connect_timeout' => 5,
                ],
            ],
        ]);

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
    public function testRejectsZeroTimeout(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'timeout' => 0,
                ],
            ],
        ]);
    }

    /**
     * Verifies that rejects negative connect timeout.
     *
     * @return void
     */
    public function testRejectsNegativeConnectTimeout(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'connect_timeout' => -1,
                ],
            ],
        ]);
    }

    /**
     * Verifies that rejects max retries above 20.
     *
     * @return void
     */
    public function testRejectsMaxRetriesAbove20(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => 21,
                ],
            ],
        ]);
    }

    /**
     * Verifies that rejects zero retry delay ms.
     *
     * @return void
     */
    public function testRejectsZeroRetryDelayMs(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retry_delay_ms' => 0,
                ],
            ],
        ]);
    }

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
     * Verifies that accepts boundary timeouts.
     *
     * @return void
     */
    public function testAcceptsBoundaryTimeouts(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'timeout' => 1,
                    'connect_timeout' => 1,
                    'retry_delay_ms' => 1,
                ],
            ],
        ]);

        $agent = $config['agents']['primary'];
        $this->assertSame(1, $agent['timeout']);
        $this->assertSame(1, $agent['connect_timeout']);
        $this->assertSame(1, $agent['retry_delay_ms']);
    }

    /**
     * Verifies that rejects negative max retries.
     *
     * @return void
     */
    public function testRejectsNegativeMaxRetries(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'max_retries' => -1,
                ],
            ],
        ]);
    }

    /**
     * Verifies that rejects negative retry delay ms.
     *
     * @return void
     */
    public function testRejectsNegativeRetryDelayMs(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retry_delay_ms' => -1,
                ],
            ],
        ]);
    }

    /**
     * Verifies that default retryable status codes.
     *
     * @return void
     */
    public function testDefaultRetryableStatusCodes(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ]);

        $this->assertSame([429, 502, 503, 504], $config['agents']['primary']['retryable_status_codes']);
    }

    /**
     * Verifies that custom retryable status codes.
     *
     * @return void
     */
    public function testCustomRetryableStatusCodes(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retryable_status_codes' => [429, 500, 502, 503],
                ],
            ],
        ]);

        $this->assertSame([429, 500, 502, 503], $config['agents']['primary']['retryable_status_codes']);
    }

    /**
     * Verifies that empty retryable status codes.
     *
     * @return void
     */
    public function testEmptyRetryableStatusCodes(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                    'retryable_status_codes' => [],
                ],
            ],
        ]);

        $this->assertSame([], $config['agents']['primary']['retryable_status_codes']);
    }

    /**
     * Verifies that default retryable fields.
     *
     * @return void
     */
    public function testDefaultRetryableFields(): void
    {
        $config = $this->processConfig([
            'agents' => [
                'primary' => [
                    'endpoint' => 'http://agent:8000',
                ],
            ],
        ]);

        $agent = $config['agents']['primary'];
        $this->assertSame(120, $agent['timeout']);
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
    }
}

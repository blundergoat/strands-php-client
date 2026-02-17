<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;

class StrandsServiceProviderTest extends TestCase
{
    public function testConfigFileExists(): void
    {
        $configPath = __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertFileExists($configPath);
    }

    public function testConfigReturnsArray(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertIsArray($config);
    }

    public function testConfigHasDefaultKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('default', $config);
    }

    public function testConfigHasAgentsKey(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $this->assertArrayHasKey('agents', $config);
        $this->assertIsArray($config['agents']);
    }

    public function testConfigDefaultAgentHasRequiredKeys(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $agent = $config['agents']['default'];

        $this->assertArrayHasKey('endpoint', $agent);
        $this->assertArrayHasKey('auth', $agent);
        $this->assertArrayHasKey('timeout', $agent);
        $this->assertArrayHasKey('connect_timeout', $agent);
        $this->assertArrayHasKey('max_retries', $agent);
        $this->assertArrayHasKey('retry_delay_ms', $agent);
    }

    public function testConfigAuthHasRequiredKeys(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $auth = $config['agents']['default']['auth'];

        $this->assertArrayHasKey('driver', $auth);
        $this->assertArrayHasKey('api_key', $auth);
        $this->assertArrayHasKey('header_name', $auth);
        $this->assertArrayHasKey('value_prefix', $auth);
    }

    public function testConfigDefaults(): void
    {
        $config = require __DIR__ . '/../../../../src/Integration/Laravel/config/strands.php';

        $agent = $config['agents']['default'];

        $this->assertSame(120, $agent['timeout']);
        $this->assertSame(10, $agent['connect_timeout']);
        $this->assertSame(0, $agent['max_retries']);
        $this->assertSame(500, $agent['retry_delay_ms']);
        $this->assertSame('Authorization', $agent['auth']['header_name']);
        $this->assertSame('Bearer ', $agent['auth']['value_prefix']);
    }
}

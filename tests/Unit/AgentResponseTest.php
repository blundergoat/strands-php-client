<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;

class AgentResponseTest extends TestCase
{
    public function testFromArrayHydratesAllFields(): void
    {
        $data = [
            'text' => 'Hello, world!',
            'agent' => 'analyst',
            'session_id' => 'sess-001',
            'has_objective' => true,
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 150],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame('Hello, world!', $response->text);
        $this->assertSame('analyst', $response->agent);
        $this->assertSame('sess-001', $response->sessionId);
        $this->assertTrue($response->hasObjective);
        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertCount(1, $response->toolsUsed);
        $this->assertSame('search', $response->toolsUsed[0]['name']);
    }

    public function testFromArrayHandlesMissingFields(): void
    {
        $data = ['text' => 'Minimal response'];

        $response = AgentResponse::fromArray($data);

        $this->assertSame('Minimal response', $response->text);
        $this->assertNull($response->agent);
        $this->assertNull($response->sessionId);
        $this->assertFalse($response->hasObjective);
        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
        $this->assertSame([], $response->toolsUsed);
    }

    public function testFromArrayHandlesEmptyUsage(): void
    {
        $data = [
            'text' => 'Test',
            'usage' => [],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
    }

    public function testFromArrayFiltersMalformedToolsUsed(): void
    {
        $data = [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 100],
                ['no_name_key' => 'value'],
                [],
                'not_an_array',
                ['name' => 'calculator'],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(2, $response->toolsUsed);
        $this->assertSame('search', $response->toolsUsed[0]['name']);
        $this->assertSame('calculator', $response->toolsUsed[1]['name']);
    }

    public function testUsageDefaultValues(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage();

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
    }

    public function testFromArrayHandlesNonIntUsageValues(): void
    {
        $data = [
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 'not_an_int',
                'output_tokens' => '42',
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
    }

    public function testFromArrayHasObjectiveRequiresStrictTrue(): void
    {
        $data = [
            'text' => 'Test',
            'has_objective' => 'true',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertFalse($response->hasObjective);
    }

    public function testFromArrayStripsNonIntDurationMs(): void
    {
        $data = [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 'fast'],
                ['name' => 'calc', 'duration_ms' => 42],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(2, $response->toolsUsed);
        $this->assertArrayNotHasKey('duration_ms', $response->toolsUsed[0]);
        $this->assertSame(42, $response->toolsUsed[1]['duration_ms']);
    }

    public function testFromArrayStripsExtraKeysFromToolsUsed(): void
    {
        $data = [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 100, 'extra_key' => 'should_be_stripped'],
                ['name' => 'calc', 'unknown' => 'also_stripped'],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(2, $response->toolsUsed);
        $this->assertSame(['name' => 'search', 'duration_ms' => 100], $response->toolsUsed[0]);
        $this->assertSame(['name' => 'calc'], $response->toolsUsed[1]);
    }
}

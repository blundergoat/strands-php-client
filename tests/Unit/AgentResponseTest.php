<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;

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
        $this->assertSame(0, $usage->cacheReadInputTokens);
        $this->assertSame(0, $usage->cacheWriteInputTokens);
        $this->assertSame(0, $usage->latencyMs);
        $this->assertSame(0, $usage->timeToFirstByteMs);
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

    public function testFromArrayHydratesStopReason(): void
    {
        $data = [
            'text' => 'Test',
            'stop_reason' => 'end_turn',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(StopReason::EndTurn, $response->stopReason);
    }

    public function testFromArrayHandlesUnknownStopReason(): void
    {
        $data = [
            'text' => 'Test',
            'stop_reason' => 'unknown_future_reason',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->stopReason);
    }

    public function testFromArrayDefaultsStopReasonToNull(): void
    {
        $data = ['text' => 'Test'];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->stopReason);
    }

    public function testFromArrayHydratesStructuredOutput(): void
    {
        $structured = ['name' => 'John', 'age' => 30, 'active' => true];
        $data = [
            'text' => 'Test',
            'structured_output' => $structured,
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame($structured, $response->structuredOutput);
    }

    public function testFromArrayDefaultsStructuredOutputToNull(): void
    {
        $data = ['text' => 'Test'];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->structuredOutput);
    }

    public function testFromArrayHydratesCacheTokens(): void
    {
        $data = [
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_read_input_tokens' => 80,
                'cache_write_input_tokens' => 20,
                'latency_ms' => 1500,
                'time_to_first_byte_ms' => 200,
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(80, $response->usage->cacheReadInputTokens);
        $this->assertSame(20, $response->usage->cacheWriteInputTokens);
        $this->assertSame(1500, $response->usage->latencyMs);
        $this->assertSame(200, $response->usage->timeToFirstByteMs);
    }

    public function testUsageDefaultsToZeroForMissingCacheFields(): void
    {
        $data = [
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(0, $response->usage->cacheReadInputTokens);
        $this->assertSame(0, $response->usage->cacheWriteInputTokens);
        $this->assertSame(0, $response->usage->latencyMs);
        $this->assertSame(0, $response->usage->timeToFirstByteMs);
    }

    public function testTotalTokensReturnsSum(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50);

        $this->assertSame(150, $usage->totalTokens());
    }

    public function testTotalTokensDefaultsToZero(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage();

        $this->assertSame(0, $usage->totalTokens());
    }

    public function testFromArrayCapturesUnknownKeysAsMetadata(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/invoke-response-with-metadata.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertSame('Response text', $response->text);
        $this->assertSame('test-agent', $response->agent);
        $this->assertSame('test-session-002', $response->sessionId);
        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);

        // Unknown keys should be captured in metadata
        $this->assertArrayHasKey('trace_id', $response->metadata);
        $this->assertSame('abc-123-def', $response->metadata['trace_id']);
        $this->assertArrayHasKey('model_id', $response->metadata);
        $this->assertSame('claude-3-sonnet', $response->metadata['model_id']);
        $this->assertArrayHasKey('request_id', $response->metadata);
        $this->assertSame('req-456', $response->metadata['request_id']);
    }

    public function testFromArrayMetadataEmptyWhenNoUnknownKeys(): void
    {
        $data = [
            'text' => 'Test',
            'agent' => 'test',
            'session_id' => 's1',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'tools_used' => [],
            'has_objective' => false,
            'stop_reason' => 'end_turn',
            'structured_output' => null,
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame([], $response->metadata);
    }

    public function testFromArrayMetadataExcludesKnownKeys(): void
    {
        $data = [
            'text' => 'Test',
            'session_id' => 's1',
            'custom_field' => 'custom_value',
        ];

        $response = AgentResponse::fromArray($data);

        // 'text' and 'session_id' should NOT be in metadata
        $this->assertArrayNotHasKey('text', $response->metadata);
        $this->assertArrayNotHasKey('session_id', $response->metadata);
        // 'custom_field' should be in metadata
        $this->assertSame('custom_value', $response->metadata['custom_field']);
    }

    public function testFromArrayHandlesAllStopReasons(): void
    {
        $reasons = [
            'end_turn' => StopReason::EndTurn,
            'tool_use' => StopReason::ToolUse,
            'max_tokens' => StopReason::MaxTokens,
            'stop_sequence' => StopReason::StopSequence,
            'content_filtered' => StopReason::ContentFiltered,
            'guardrail_intervened' => StopReason::GuardrailIntervened,
            'interrupt' => StopReason::Interrupt,
        ];

        foreach ($reasons as $raw => $expected) {
            $response = AgentResponse::fromArray(['text' => 'Test', 'stop_reason' => $raw]);
            $this->assertSame($expected, $response->stopReason, "Failed for stop_reason: $raw");
        }
    }

    public function testFromArrayParsesInterrupts(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/invoke-interrupt-response.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertTrue($response->isInterrupted());
        $this->assertCount(1, $response->interrupts);
        $this->assertInstanceOf(InterruptDetail::class, $response->interrupts[0]);
        $this->assertSame('deploy', $response->interrupts[0]->toolName);
        $this->assertSame(['environment' => 'production', 'version' => '2.0.0'], $response->interrupts[0]->toolInput);
        $this->assertSame('tu-001', $response->interrupts[0]->toolUseId);
        $this->assertSame('int-abc-123', $response->interrupts[0]->interruptId);
        $this->assertSame('Production deployment requires approval', $response->interrupts[0]->reason);
        $this->assertSame(StopReason::Interrupt, $response->stopReason);
    }

    public function testFromArrayNoInterruptsDefaultsEmpty(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertFalse($response->isInterrupted());
        $this->assertSame([], $response->interrupts);
    }

    public function testFromArrayParsesGuardrailTrace(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/invoke-guardrail-response.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertNotNull($response->guardrailTrace);
        $this->assertInstanceOf(GuardrailTrace::class, $response->guardrailTrace);
        $this->assertSame('INTERVENED', $response->guardrailTrace->action);
        $this->assertCount(1, $response->guardrailTrace->assessments);
        $this->assertSame('content_filter', $response->guardrailTrace->assessments[0]['type']);
        $this->assertSame('The original unsafe response text', $response->guardrailTrace->modelOutput);
        $this->assertSame(StopReason::GuardrailIntervened, $response->stopReason);
    }

    public function testFromArrayGuardrailTraceFromNestedTrace(): void
    {
        $data = [
            'text' => 'Blocked',
            'trace' => [
                'guardrail' => [
                    'action' => 'INTERVENED',
                    'assessments' => [],
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertNotNull($response->guardrailTrace);
        $this->assertSame('INTERVENED', $response->guardrailTrace->action);
    }

    public function testFromArrayGuardrailTraceDefaultsToNull(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($response->guardrailTrace);
    }

    public function testFromArrayParsesCitations(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/invoke-response-with-citations.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertCount(1, $response->citations);
        $this->assertSame('citationsContent', $response->citations[0]['type']);
        $this->assertSame('https://example.com/docs', $response->citations[0]['source']);
        $this->assertSame('Official Documentation', $response->citations[0]['title']);
    }

    public function testFromArrayCitationsDefaultsToEmpty(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $response->citations);
    }

    public function testFromArrayCitationsIgnoresNonCitationBlocks(): void
    {
        $data = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'citationsContent', 'source' => 'url'],
                    ['type' => 'image', 'data' => 'abc'],
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(1, $response->citations);
        $this->assertSame('citationsContent', $response->citations[0]['type']);
    }

    public function testInterruptsExcludedFromMetadata(): void
    {
        $data = [
            'text' => 'Test',
            'interrupts' => [],
            'custom' => 'value',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertArrayNotHasKey('interrupts', $response->metadata);
        $this->assertSame('value', $response->metadata['custom']);
    }

    public function testGuardrailTraceExcludedFromMetadata(): void
    {
        $data = [
            'text' => 'Test',
            'guardrail_trace' => ['action' => 'NONE'],
            'trace' => ['guardrail' => ['action' => 'NONE']],
            'message' => ['content' => []],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertArrayNotHasKey('guardrail_trace', $response->metadata);
        $this->assertArrayNotHasKey('trace', $response->metadata);
        $this->assertArrayNotHasKey('message', $response->metadata);
    }

    public function testHasObjectiveDefaultValue(): void
    {
        $response = new AgentResponse(text: 'Test');

        $this->assertFalse($response->hasObjective);
    }

    public function testMultipleInterruptsAllReturned(): void
    {
        $data = [
            'text' => 'Test',
            'stop_reason' => 'interrupt',
            'interrupts' => [
                [
                    'tool_name' => 'deploy',
                    'tool_input' => ['env' => 'prod'],
                    'tool_use_id' => 'tu-1',
                    'interrupt_id' => 'int-1',
                    'reason' => 'First approval',
                ],
                [
                    'tool_name' => 'scale',
                    'tool_input' => ['count' => 5],
                    'tool_use_id' => 'tu-2',
                    'interrupt_id' => 'int-2',
                    'reason' => 'Second approval',
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(2, $response->interrupts);
        $this->assertSame('deploy', $response->interrupts[0]->toolName);
        $this->assertSame('scale', $response->interrupts[1]->toolName);
    }

    public function testMultipleCitationsAllReturned(): void
    {
        $data = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'citationsContent', 'source' => 'url1', 'title' => 'Doc 1'],
                    ['type' => 'text', 'text' => 'some text'],
                    ['type' => 'citationsContent', 'source' => 'url2', 'title' => 'Doc 2'],
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertCount(2, $response->citations);
        $this->assertSame('url1', $response->citations[0]['source']);
        $this->assertSame('url2', $response->citations[1]['source']);
    }

    public function testCitationsReturnsEmptyWhenMessageNotArray(): void
    {
        $data = [
            'text' => 'Test',
            'message' => 'not an array',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame([], $response->citations);
    }
}

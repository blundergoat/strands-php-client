<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;

class AgentResponseTest extends TestCase
{
    /**
     * Verifies that from array hydrates all fields.
     *
     * @return void
     */
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

    /**
     * Verifies that from array handles missing fields.
     *
     * @return void
     */
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

    /**
     * Verifies that from array handles empty usage.
     *
     * @return void
     */
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

    /**
     * Verifies that from array filters malformed tools used.
     *
     * @return void
     */
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

    /**
     * Verifies that usage default values.
     *
     * @return void
     */
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

    /**
     * Verifies that from array handles non int usage values.
     *
     * @return void
     */
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
        $this->assertSame(42, $response->usage->outputTokens);
    }

    /**
     * Verifies that from array has objective requires strict true.
     *
     * @return void
     */
    public function testFromArrayHasObjectiveRequiresStrictTrue(): void
    {
        $data = [
            'text' => 'Test',
            'has_objective' => 'true',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertFalse($response->hasObjective);
    }

    /**
     * Verifies that from array strips non int duration ms.
     *
     * @return void
     */
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

    /**
     * Verifies that from array strips extra keys from tools used.
     *
     * @return void
     */
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

    /**
     * Verifies that from array parses safe tool summaries.
     *
     * @return void
     */
    public function testFromArrayParsesSafeToolSummaries(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/wire-contract/invoke-response-tools-full.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertSame('availability_lookup', $response->toolsUsed[0]['name']);
        $this->assertSame(114, $response->toolsUsed[0]['duration_ms']);
        $this->assertSame(['summary' => 'date lookup'], $response->toolsUsed[0]['input']);
        $this->assertSame(['summary' => 'slot available'], $response->toolsUsed[0]['result']);
    }

    /**
     * Verifies that from array hydrates stop reason.
     *
     * @return void
     */
    public function testFromArrayHydratesStopReason(): void
    {
        $data = [
            'text' => 'Test',
            'stop_reason' => 'end_turn',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame(StopReason::EndTurn, $response->stopReason);
    }

    /**
     * Verifies that from array handles unknown stop reason.
     *
     * @return void
     */
    public function testFromArrayHandlesUnknownStopReason(): void
    {
        $data = [
            'text' => 'Test',
            'stop_reason' => 'unknown_future_reason',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->stopReason);
        $this->assertSame('unknown_future_reason', $response->rawStopReason);
    }

    /**
     * Verifies that from array defaults stop reason to null.
     *
     * @return void
     */
    public function testFromArrayDefaultsStopReasonToNull(): void
    {
        $data = ['text' => 'Test'];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->stopReason);
    }

    /**
     * Verifies that from array hydrates structured output.
     *
     * @return void
     */
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

    /**
     * Verifies that from array defaults structured output to null.
     *
     * @return void
     */
    public function testFromArrayDefaultsStructuredOutputToNull(): void
    {
        $data = ['text' => 'Test'];

        $response = AgentResponse::fromArray($data);

        $this->assertNull($response->structuredOutput);
    }

    /**
     * Verifies that from array hydrates cache tokens.
     *
     * @return void
     */
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

    /**
     * Verifies that from array parses usage camel case and float latency.
     *
     * @return void
     */
    public function testFromArrayParsesUsageCamelCaseAndFloatLatency(): void
    {
        $response = AgentResponse::fromArray([
            'text' => 'Test',
            'usage' => [
                'inputTokens' => 100,
                'outputTokens' => '50',
                'totalTokens' => 151,
                'cacheReadInputTokens' => '10',
                'cacheWriteInputTokens' => 5.0,
                'latencyMs' => 842.5,
                'timeToFirstByteMs' => '210.1',
            ],
        ]);

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(151, $response->usage->totalTokens());
        $this->assertSame(10, $response->usage->cacheReadInputTokens);
        $this->assertSame(5, $response->usage->cacheWriteInputTokens);
        $this->assertSame(843, $response->usage->latencyMs);
        $this->assertSame(210, $response->usage->timeToFirstByteMs);
    }

    /**
     * Verifies that snake case usage wins over camel case.
     *
     * @return void
     */
    public function testSnakeCaseUsageWinsOverCamelCase(): void
    {
        $response = AgentResponse::fromArray([
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 10,
                'inputTokens' => 999,
            ],
        ]);

        $this->assertSame(10, $response->usage->inputTokens);
    }

    /**
     * Verifies that usage defaults to zero for missing cache fields.
     *
     * @return void
     */
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

    /**
     * Verifies that total tokens returns sum.
     *
     * @return void
     */
    public function testTotalTokensReturnsSum(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50);

        $this->assertSame(150, $usage->totalTokens());
    }

    /**
     * Verifies that total tokens uses wire value when present.
     *
     * @return void
     */
    public function testTotalTokensUsesWireValueWhenPresent(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50, totalTokens: 160);

        $this->assertSame(160, $usage->totalTokens());
    }

    /**
     * Verifies that total tokens defaults to zero.
     *
     * @return void
     */
    public function testTotalTokensDefaultsToZero(): void
    {
        $usage = new \StrandsPhpClient\Response\Usage();

        $this->assertSame(0, $usage->totalTokens());
    }

    /**
     * Verifies that from array captures unknown keys as metadata.
     *
     * @return void
     */
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

    /**
     * Verifies that from array preserves top level wrapper metadata separately.
     *
     * @return void
     */
    public function testFromArrayPreservesTopLevelWrapperMetadataSeparately(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/wire-contract/invoke-response-metadata.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertSame(['document_type' => 'referral', 'confidence' => 0.91], $response->wrapperMetadata);
        $this->assertArrayHasKey('metadata', $response->metadata);
    }

    /**
     * Verifies that from array preserves nested message metadata.
     *
     * @return void
     */
    public function testFromArrayPreservesNestedMessageMetadata(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/wire-contract/invoke-response-message-metadata.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertNotNull($response->message);
        $this->assertSame('assistant', $response->message->role);
        $this->assertNotNull($response->message->metadata);
        $this->assertSame(410, $response->message->metadata->usage?->inputTokens);
        $this->assertSame(842.5, $response->message->metadata->metrics['latency_ms']);
        $this->assertSame('referral', $response->message->metadata->custom['document_type']);
    }

    /**
     * Verifies that from array parses context size fields.
     *
     * @return void
     */
    public function testFromArrayParsesContextSizeFields(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/wire-contract/invoke-response-context-size.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);

        $this->assertSame(8192, $response->contextSize);
        $this->assertSame(9216, $response->projectedContextSize);
    }

    /**
     * Verifies that from array metadata empty when no unknown keys.
     *
     * @return void
     */
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

    /**
     * Verifies that from array metadata excludes known keys.
     *
     * @return void
     */
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

    /**
     * Verifies that from array handles all stop reasons.
     *
     * @return void
     */
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
            'cancelled' => StopReason::Cancelled,
            'checkpoint' => StopReason::Checkpoint,
        ];

        foreach ($reasons as $raw => $expected) {
            $response = AgentResponse::fromArray(['text' => 'Test', 'stop_reason' => $raw]);
            $this->assertSame($expected, $response->stopReason, "Failed for stop_reason: $raw");
        }
    }

    /**
     * Verifies that from array parses interrupts.
     *
     * @return void
     */
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

    /**
     * Verifies that from array no interrupts defaults empty.
     *
     * @return void
     */
    public function testFromArrayNoInterruptsDefaultsEmpty(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertFalse($response->isInterrupted());
        $this->assertSame([], $response->interrupts);
    }

    /**
     * Verifies that from array parses guardrail trace.
     *
     * @return void
     */
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

    /**
     * Verifies that from array guardrail trace from nested trace.
     *
     * @return void
     */
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

    /**
     * Verifies that from array guardrail trace defaults to null.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceDefaultsToNull(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($response->guardrailTrace);
    }

    /**
     * Verifies that from array parses citations.
     *
     * @return void
     */
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

    /**
     * Verifies that from array citations defaults to empty.
     *
     * @return void
     */
    public function testFromArrayCitationsDefaultsToEmpty(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $response->citations);
    }

    /**
     * Verifies that from array citations ignores non citation blocks.
     *
     * @return void
     */
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

    /**
     * Verifies that interrupts excluded from metadata.
     *
     * @return void
     */
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

    /**
     * Verifies that guardrail trace excluded from metadata.
     *
     * @return void
     */
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

    /**
     * Verifies that has objective default value.
     *
     * @return void
     */
    public function testHasObjectiveDefaultValue(): void
    {
        $response = new AgentResponse(text: 'Test');

        $this->assertFalse($response->hasObjective);
    }

    /**
     * Verifies that multiple interrupts all returned.
     *
     * @return void
     */
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

    /**
     * Verifies that multiple citations all returned.
     *
     * @return void
     */
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

    /**
     * Verifies that citations returns empty when message not array.
     *
     * @return void
     */
    public function testCitationsReturnsEmptyWhenMessageNotArray(): void
    {
        $data = [
            'text' => 'Test',
            'message' => 'not an array',
        ];

        $response = AgentResponse::fromArray($data);

        $this->assertSame([], $response->citations);
    }

    /**
     * Verifies that get citation objects returns typed list.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsTypedList(): void
    {
        $data = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    [
                        'type' => 'citationsContent',
                        'location' => ['type' => 'WEB', 'url' => 'https://example.com'],
                        'source_content' => ['text' => 'source text'],
                    ],
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);
        $citations = $response->getCitationObjects();

        $this->assertCount(1, $citations);
        $this->assertInstanceOf(Citation::class, $citations[0]);
        $this->assertSame('WEB', $citations[0]->location?->type);
        $this->assertSame('source text', $citations[0]->sourceContent?->text);
    }

    /**
     * Verifies that get citation objects preserves flat citation fields.
     *
     * @return void
     */
    public function testGetCitationObjectsPreservesFlatCitationFields(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/invoke-response-with-citations.json'),
            true,
        );

        $response = AgentResponse::fromArray($data);
        $citations = $response->getCitationObjects();

        $this->assertCount(1, $citations);
        $this->assertSame('https://example.com/docs', $citations[0]->source);
        $this->assertSame('Official Documentation', $citations[0]->title);
        $this->assertSame('the answer is 42', $citations[0]->text);
        $this->assertSame('https://example.com/docs', $citations[0]->location?->url);
        $this->assertSame('the answer is 42', $citations[0]->sourceContent?->text);
    }

    /**
     * Verifies that get citation objects caches result.
     *
     * @return void
     */
    public function testGetCitationObjectsCachesResult(): void
    {
        $data = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'citationsContent', 'location' => ['type' => 'WEB']],
                ],
            ],
        ];

        $response = AgentResponse::fromArray($data);
        $first = $response->getCitationObjects();
        $second = $response->getCitationObjects();

        $this->assertSame($first, $second);
    }

    /**
     * Verifies that get citation objects returns empty for no citations.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsEmptyForNoCitations(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $response->getCitationObjects());
    }

    /**
     * Verifies that structured output as with from array factory.
     *
     * @return void
     */
    public function testStructuredOutputAsWithFromArrayFactory(): void
    {
        $data = [
            'text' => 'Test',
            'structured_output' => ['name' => 'John', 'age' => 30],
        ];

        $response = AgentResponse::fromArray($data);
        $dto = $response->structuredOutputAs(TestStructuredDto::class);

        $this->assertInstanceOf(TestStructuredDto::class, $dto);
        $this->assertSame('John', $dto->name);
        $this->assertSame(30, $dto->age);
    }

    /**
     * Verifies that structured output as with constructor.
     *
     * @return void
     */
    public function testStructuredOutputAsWithConstructor(): void
    {
        $data = [
            'text' => 'Test',
            'structured_output' => ['name' => 'Jane', 'score' => 95.5],
        ];

        $response = AgentResponse::fromArray($data);
        $dto = $response->structuredOutputAs(TestConstructorDto::class);

        $this->assertInstanceOf(TestConstructorDto::class, $dto);
        $this->assertSame('Jane', $dto->name);
        $this->assertSame(95.5, $dto->score);
    }

    /**
     * Verifies that structured output as throws when null.
     *
     * @return void
     */
    public function testStructuredOutputAsThrowsWhenNull(): void
    {
        $response = AgentResponse::fromArray(['text' => 'Test']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No structured output in response');
        $response->structuredOutputAs(TestStructuredDto::class);
    }

    /**
     * Verifies that structured output as throws on mismatch.
     *
     * @return void
     */
    public function testStructuredOutputAsThrowsOnMismatch(): void
    {
        $data = [
            'text' => 'Test',
            'structured_output' => ['wrong_key' => 'value'],
        ];

        $response = AgentResponse::fromArray($data);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to hydrate/');
        $response->structuredOutputAs(TestConstructorDto::class);
    }
}

/**
 * @internal
 */
class TestStructuredDto
{
    /**
     * Create a test fixture DTO.
     *
     * @param string $name Fixture name or DTO name under test.
     * @param int $age DTO age value used by the fixture.
     */
    public function __construct(
        public readonly string $name = '',
        public readonly int $age = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            age: is_int($data['age'] ?? null) ? $data['age'] : 0,
        );
    }
}

/**
 * @internal
 */
class TestConstructorDto
{
    /**
     * Create a test fixture DTO.
     *
     * @param string $name Fixture name or DTO name under test.
     * @param float $score DTO score value used by the fixture.
     */
    public function __construct(
        public readonly string $name,
        public readonly float $score,
    ) {
    }
}

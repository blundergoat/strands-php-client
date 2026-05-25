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
     * Load and JSON-decode a fixture file under tests/Fixtures/.
     *
     * @param string $relativePath Path relative to tests/Fixtures/.
     * @return array<string, mixed> Decoded JSON fixture.
     */
    private function loadJsonFixture(string $relativePath): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/' . $relativePath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
    /**
     * Data fixture for testFromArrayHydratesAllFields().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHydratesAllFields(): array
    {
        return [
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
    }


    /**
     * Verifies that from array hydrates all fields.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $data = $this->dataForFromArrayHydratesAllFields();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame('Hello, world!', $agentResponse->text);
        $this->assertSame('analyst', $agentResponse->agent);
        $this->assertSame('sess-001', $agentResponse->sessionId);
        $this->assertTrue($agentResponse->hasObjective);
        $this->assertSame(100, $agentResponse->usage->inputTokens);
        $this->assertSame(50, $agentResponse->usage->outputTokens);
        $this->assertCount(1, $agentResponse->toolsUsed);
        $this->assertSame('search', $agentResponse->toolsUsed[0]['name']);
    }

    /**
     * Verifies that from array handles missing fields.
     *
     * @return void
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $data = ['text' => 'Minimal response'];

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame('Minimal response', $agentResponse->text);
        $this->assertNull($agentResponse->agent);
        $this->assertNull($agentResponse->sessionId);
        $this->assertFalse($agentResponse->hasObjective);
        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(0, $agentResponse->usage->outputTokens);
        $this->assertSame([], $agentResponse->toolsUsed);
    }
    /**
     * Data fixture for testFromArrayHandlesEmptyUsage().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHandlesEmptyUsage(): array
    {
        return [
            'text' => 'Test',
            'usage' => [],
        ];
    }


    /**
     * Verifies that from array handles empty usage.
     *
     * @return void
     */
    public function testFromArrayHandlesEmptyUsage(): void
    {
        $data = $this->dataForFromArrayHandlesEmptyUsage();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(0, $agentResponse->usage->outputTokens);
    }
    /**
     * Data fixture for testFromArrayFiltersMalformedToolsUsed().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayFiltersMalformedToolsUsed(): array
    {
        return [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 100],
                ['no_name_key' => 'value'],
                [],
                'not_an_array',
                ['name' => 'calculator'],
            ],
        ];
    }


    /**
     * Verifies that from array filters malformed tools used.
     *
     * @return void
     */
    public function testFromArrayFiltersMalformedToolsUsed(): void
    {
        $data = $this->dataForFromArrayFiltersMalformedToolsUsed();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertSame('search', $agentResponse->toolsUsed[0]['name']);
        $this->assertSame('calculator', $agentResponse->toolsUsed[1]['name']);
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
     * Data fixture for testFromArrayHandlesNonIntUsageValues().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHandlesNonIntUsageValues(): array
    {
        return [
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 'not_an_int',
                'output_tokens' => '42',
            ],
        ];
    }


    /**
     * Verifies that from array handles non int usage values.
     *
     * @return void
     */
    public function testFromArrayHandlesNonIntUsageValues(): void
    {
        $data = $this->dataForFromArrayHandlesNonIntUsageValues();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(42, $agentResponse->usage->outputTokens);
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

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertFalse($agentResponse->hasObjective);
    }
    /**
     * Data fixture for testFromArrayStripsNonIntDurationMs().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayStripsNonIntDurationMs(): array
    {
        return [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 'fast'],
                ['name' => 'calc', 'duration_ms' => 42],
            ],
        ];
    }


    /**
     * Verifies that from array strips non int duration ms.
     *
     * @return void
     */
    public function testFromArrayStripsNonIntDurationMs(): void
    {
        $data = $this->dataForFromArrayStripsNonIntDurationMs();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertArrayNotHasKey('duration_ms', $agentResponse->toolsUsed[0]);
        $this->assertSame(42, $agentResponse->toolsUsed[1]['duration_ms']);
    }
    /**
     * Data fixture for testFromArrayStripsExtraKeysFromToolsUsed().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayStripsExtraKeysFromToolsUsed(): array
    {
        return [
            'text' => 'Test',
            'tools_used' => [
                ['name' => 'search', 'duration_ms' => 100, 'extra_key' => 'should_be_stripped'],
                ['name' => 'calc', 'unknown' => 'also_stripped'],
            ],
        ];
    }


    /**
     * Verifies that from array strips extra keys from tools used.
     *
     * @return void
     */
    public function testFromArrayStripsExtraKeysFromToolsUsed(): void
    {
        $data = $this->dataForFromArrayStripsExtraKeysFromToolsUsed();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertSame(['name' => 'search', 'duration_ms' => 100], $agentResponse->toolsUsed[0]);
        $this->assertSame(['name' => 'calc'], $agentResponse->toolsUsed[1]);
    }

    /**
     * Verifies that from array parses safe tool summaries.
     *
     * @return void
     */
    public function testFromArrayParsesSafeToolSummaries(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-tools-full.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame('availability_lookup', $agentResponse->toolsUsed[0]['name']);
        $this->assertSame(114, $agentResponse->toolsUsed[0]['duration_ms']);
        $this->assertSame(['summary' => 'date lookup'], $agentResponse->toolsUsed[0]['input']);
        $this->assertSame(['summary' => 'slot available'], $agentResponse->toolsUsed[0]['result']);
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

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(StopReason::EndTurn, $agentResponse->stopReason);
    }
    /**
     * Data fixture for testFromArrayHandlesUnknownStopReason().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHandlesUnknownStopReason(): array
    {
        return [
            'text' => 'Test',
            'stop_reason' => 'unknown_future_reason',
        ];
    }


    /**
     * Verifies that from array handles unknown stop reason.
     *
     * @return void
     */
    public function testFromArrayHandlesUnknownStopReason(): void
    {
        $data = $this->dataForFromArrayHandlesUnknownStopReason();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNull($agentResponse->stopReason);
        $this->assertSame('unknown_future_reason', $agentResponse->rawStopReason);
    }

    /**
     * Verifies that from array defaults stop reason to null.
     *
     * @return void
     */
    public function testFromArrayDefaultsStopReasonToNull(): void
    {
        $data = ['text' => 'Test'];

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNull($agentResponse->stopReason);
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

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame($structured, $agentResponse->structuredOutput);
    }

    /**
     * Verifies that from array defaults structured output to null.
     *
     * @return void
     */
    public function testFromArrayDefaultsStructuredOutputToNull(): void
    {
        $data = ['text' => 'Test'];

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNull($agentResponse->structuredOutput);
    }
    /**
     * Data fixture for testFromArrayHydratesCacheTokens().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHydratesCacheTokens(): array
    {
        return [
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
    }


    /**
     * Verifies that from array hydrates cache tokens.
     *
     * @return void
     */
    public function testFromArrayHydratesCacheTokens(): void
    {
        $data = $this->dataForFromArrayHydratesCacheTokens();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(100, $agentResponse->usage->inputTokens);
        $this->assertSame(50, $agentResponse->usage->outputTokens);
        $this->assertSame(80, $agentResponse->usage->cacheReadInputTokens);
        $this->assertSame(20, $agentResponse->usage->cacheWriteInputTokens);
        $this->assertSame(1500, $agentResponse->usage->latencyMs);
        $this->assertSame(200, $agentResponse->usage->timeToFirstByteMs);
    }

    /**
     * Verifies that from array parses usage camel case and float latency.
     *
     * @return void
     */
    public function testFromArrayParsesUsageCamelCaseAndFloatLatency(): void
    {
        $agentResponse = AgentResponse::fromArray([
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

        $this->assertSame(100, $agentResponse->usage->inputTokens);
        $this->assertSame(50, $agentResponse->usage->outputTokens);
        $this->assertSame(151, $agentResponse->usage->totalTokens());
        $this->assertSame(10, $agentResponse->usage->cacheReadInputTokens);
        $this->assertSame(5, $agentResponse->usage->cacheWriteInputTokens);
        $this->assertSame(843, $agentResponse->usage->latencyMs);
        $this->assertSame(210, $agentResponse->usage->timeToFirstByteMs);
    }

    /**
     * Verifies that snake case usage wins over camel case.
     *
     * @return void
     */
    public function testSnakeCaseUsageWinsOverCamelCase(): void
    {
        $agentResponse = AgentResponse::fromArray([
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 10,
                'inputTokens' => 999,
            ],
        ]);

        $this->assertSame(10, $agentResponse->usage->inputTokens);
    }
    /**
     * Data fixture for testUsageDefaultsToZeroForMissingCacheFields().
     *
     * @return array<string, mixed>
     */
    private function dataForUsageDefaultsToZeroForMissingCacheFields(): array
    {
        return [
            'text' => 'Test',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ];
    }


    /**
     * Verifies that usage defaults to zero for missing cache fields.
     *
     * @return void
     */
    public function testUsageDefaultsToZeroForMissingCacheFields(): void
    {
        $data = $this->dataForUsageDefaultsToZeroForMissingCacheFields();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(0, $agentResponse->usage->cacheReadInputTokens);
        $this->assertSame(0, $agentResponse->usage->cacheWriteInputTokens);
        $this->assertSame(0, $agentResponse->usage->latencyMs);
        $this->assertSame(0, $agentResponse->usage->timeToFirstByteMs);
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
        $data = $this->loadJsonFixture('invoke-response-with-metadata.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame('Response text', $agentResponse->text);
        $this->assertSame('test-agent', $agentResponse->agent);
        $this->assertSame('test-session-002', $agentResponse->sessionId);
        $this->assertSame(100, $agentResponse->usage->inputTokens);
        $this->assertSame(50, $agentResponse->usage->outputTokens);

        // Unknown keys should be captured in metadata
        $this->assertArrayHasKey('trace_id', $agentResponse->metadata);
        $this->assertSame('abc-123-def', $agentResponse->metadata['trace_id']);
        $this->assertArrayHasKey('model_id', $agentResponse->metadata);
        $this->assertSame('claude-3-sonnet', $agentResponse->metadata['model_id']);
        $this->assertArrayHasKey('request_id', $agentResponse->metadata);
        $this->assertSame('req-456', $agentResponse->metadata['request_id']);
    }

    /**
     * Verifies that from array preserves top level wrapper metadata separately.
     *
     * @return void
     */
    public function testFromArrayPreservesTopLevelWrapperMetadataSeparately(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-metadata.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(['document_type' => 'referral', 'confidence' => 0.91], $agentResponse->wrapperMetadata);
        $this->assertArrayHasKey('metadata', $agentResponse->metadata);
    }

    /**
     * Verifies that from array preserves nested message metadata.
     *
     * @return void
     */
    public function testFromArrayPreservesNestedMessageMetadata(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-message-metadata.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNotNull($agentResponse->message);
        $this->assertSame('assistant', $agentResponse->message->role);
        $this->assertNotNull($agentResponse->message->metadata);
        $this->assertSame(410, $agentResponse->message->metadata->usage?->inputTokens);
        $this->assertSame(842.5, $agentResponse->message->metadata->metrics['latency_ms']);
        $this->assertSame('referral', $agentResponse->message->metadata->custom['document_type']);
    }

    /**
     * Verifies that from array parses context size fields.
     *
     * @return void
     */
    public function testFromArrayParsesContextSizeFields(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-context-size.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame(8192, $agentResponse->contextSize);
        $this->assertSame(9216, $agentResponse->projectedContextSize);
    }
    /**
     * Data fixture for testFromArrayMetadataEmptyWhenNoUnknownKeys().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayMetadataEmptyWhenNoUnknownKeys(): array
    {
        return [
            'text' => 'Test',
            'agent' => 'test',
            'session_id' => 's1',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'tools_used' => [],
            'has_objective' => false,
            'stop_reason' => 'end_turn',
            'structured_output' => null,
        ];
    }


    /**
     * Verifies that from array metadata empty when no unknown keys.
     *
     * @return void
     */
    public function testFromArrayMetadataEmptyWhenNoUnknownKeys(): void
    {
        $data = $this->dataForFromArrayMetadataEmptyWhenNoUnknownKeys();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame([], $agentResponse->metadata);
    }
    /**
     * Data fixture for testFromArrayMetadataExcludesKnownKeys().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayMetadataExcludesKnownKeys(): array
    {
        return [
            'text' => 'Test',
            'session_id' => 's1',
            'custom_field' => 'custom_value',
        ];
    }


    /**
     * Verifies that from array metadata excludes known keys.
     *
     * @return void
     */
    public function testFromArrayMetadataExcludesKnownKeys(): void
    {
        $data = $this->dataForFromArrayMetadataExcludesKnownKeys();

        $agentResponse = AgentResponse::fromArray($data);

        // 'text' and 'session_id' should NOT be in metadata
        $this->assertArrayNotHasKey('text', $agentResponse->metadata);
        $this->assertArrayNotHasKey('session_id', $agentResponse->metadata);
        // 'custom_field' should be in metadata
        $this->assertSame('custom_value', $agentResponse->metadata['custom_field']);
    }
    /**
     * Data fixture for testFromArrayHandlesAllStopReasons().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayHandlesAllStopReasons(): array
    {
        return [
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
    }


    /**
     * Verifies that from array handles all stop reasons.
     *
     * @return void
     */
    public function testFromArrayHandlesAllStopReasons(): void
    {
        $reasons = $this->dataForFromArrayHandlesAllStopReasons();

        foreach ($reasons as $raw => $expected) {
            $agentResponse = AgentResponse::fromArray(['text' => 'Test', 'stop_reason' => $raw]);
            $this->assertSame($expected, $agentResponse->stopReason, "Failed for stop_reason: $raw");
        }
    }

    /**
     * Verifies that from array parses interrupts.
     *
     * @return void
     */
    public function testFromArrayParsesInterrupts(): void
    {
        $data = $this->loadJsonFixture('invoke-interrupt-response.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertTrue($agentResponse->isInterrupted());
        $this->assertCount(1, $agentResponse->interrupts);
        $this->assertInstanceOf(InterruptDetail::class, $agentResponse->interrupts[0]);
        $this->assertSame('deploy', $agentResponse->interrupts[0]->toolName);
        $this->assertSame(['environment' => 'production', 'version' => '2.0.0'], $agentResponse->interrupts[0]->toolInput);
        $this->assertSame('tu-001', $agentResponse->interrupts[0]->toolUseId);
        $this->assertSame('int-abc-123', $agentResponse->interrupts[0]->interruptId);
        $this->assertSame('Production deployment requires approval', $agentResponse->interrupts[0]->reason);
        $this->assertSame(StopReason::Interrupt, $agentResponse->stopReason);
    }

    /**
     * Verifies that from array no interrupts defaults empty.
     *
     * @return void
     */
    public function testFromArrayNoInterruptsDefaultsEmpty(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertFalse($agentResponse->isInterrupted());
        $this->assertSame([], $agentResponse->interrupts);
    }

    /**
     * Verifies that from array parses guardrail trace.
     *
     * @return void
     */
    public function testFromArrayParsesGuardrailTrace(): void
    {
        $data = $this->loadJsonFixture('invoke-guardrail-response.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNotNull($agentResponse->guardrailTrace);
        $this->assertInstanceOf(GuardrailTrace::class, $agentResponse->guardrailTrace);
        $this->assertSame('INTERVENED', $agentResponse->guardrailTrace->action);
        $this->assertCount(1, $agentResponse->guardrailTrace->assessments);
        $this->assertSame('content_filter', $agentResponse->guardrailTrace->assessments[0]['type']);
        $this->assertSame('The original unsafe response text', $agentResponse->guardrailTrace->modelOutput);
        $this->assertSame(StopReason::GuardrailIntervened, $agentResponse->stopReason);
    }
    /**
     * Data fixture for testFromArrayGuardrailTraceFromNestedTrace().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayGuardrailTraceFromNestedTrace(): array
    {
        return [
            'text' => 'Blocked',
            'trace' => [
                'guardrail' => [
                    'action' => 'INTERVENED',
                    'assessments' => [],
                ],
            ],
        ];
    }


    /**
     * Verifies that from array guardrail trace from nested trace.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceFromNestedTrace(): void
    {
        $data = $this->dataForFromArrayGuardrailTraceFromNestedTrace();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertNotNull($agentResponse->guardrailTrace);
        $this->assertSame('INTERVENED', $agentResponse->guardrailTrace->action);
    }

    /**
     * Verifies that from array guardrail trace defaults to null.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceDefaultsToNull(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($agentResponse->guardrailTrace);
    }

    /**
     * Verifies that from array parses citations.
     *
     * @return void
     */
    public function testFromArrayParsesCitations(): void
    {
        $data = $this->loadJsonFixture('invoke-response-with-citations.json');

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(1, $agentResponse->citations);
        $this->assertSame('citationsContent', $agentResponse->citations[0]['type']);
        $this->assertSame('https://example.com/docs', $agentResponse->citations[0]['source']);
        $this->assertSame('Official Documentation', $agentResponse->citations[0]['title']);
    }

    /**
     * Verifies that from array citations defaults to empty.
     *
     * @return void
     */
    public function testFromArrayCitationsDefaultsToEmpty(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->citations);
    }
    /**
     * Data fixture for testFromArrayCitationsIgnoresNonCitationBlocks().
     *
     * @return array<string, mixed>
     */
    private function dataForFromArrayCitationsIgnoresNonCitationBlocks(): array
    {
        return [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'citationsContent', 'source' => 'url'],
                    ['type' => 'image', 'data' => 'abc'],
                ],
            ],
        ];
    }


    /**
     * Verifies that from array citations ignores non citation blocks.
     *
     * @return void
     */
    public function testFromArrayCitationsIgnoresNonCitationBlocks(): void
    {
        $data = $this->dataForFromArrayCitationsIgnoresNonCitationBlocks();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(1, $agentResponse->citations);
        $this->assertSame('citationsContent', $agentResponse->citations[0]['type']);
    }
    /**
     * Data fixture for testInterruptsExcludedFromMetadata().
     *
     * @return array<string, mixed>
     */
    private function dataForInterruptsExcludedFromMetadata(): array
    {
        return [
            'text' => 'Test',
            'interrupts' => [],
            'custom' => 'value',
        ];
    }


    /**
     * Verifies that interrupts excluded from metadata.
     *
     * @return void
     */
    public function testInterruptsExcludedFromMetadata(): void
    {
        $data = $this->dataForInterruptsExcludedFromMetadata();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertArrayNotHasKey('interrupts', $agentResponse->metadata);
        $this->assertSame('value', $agentResponse->metadata['custom']);
    }
    /**
     * Data fixture for testGuardrailTraceExcludedFromMetadata().
     *
     * @return array<string, mixed>
     */
    private function dataForGuardrailTraceExcludedFromMetadata(): array
    {
        return [
            'text' => 'Test',
            'guardrail_trace' => ['action' => 'NONE'],
            'trace' => ['guardrail' => ['action' => 'NONE']],
            'message' => ['content' => []],
        ];
    }


    /**
     * Verifies that guardrail trace excluded from metadata.
     *
     * @return void
     */
    public function testGuardrailTraceExcludedFromMetadata(): void
    {
        $data = $this->dataForGuardrailTraceExcludedFromMetadata();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertArrayNotHasKey('guardrail_trace', $agentResponse->metadata);
        $this->assertArrayNotHasKey('trace', $agentResponse->metadata);
        $this->assertArrayNotHasKey('message', $agentResponse->metadata);
    }

    /**
     * Verifies that has objective default value.
     *
     * @return void
     */
    public function testHasObjectiveDefaultValue(): void
    {
        $agentResponse = new AgentResponse(text: 'Test');

        $this->assertFalse($agentResponse->hasObjective);
    }
    /**
     * Data fixture for testMultipleInterruptsAllReturned().
     *
     * @return array<string, mixed>
     */
    private function dataForMultipleInterruptsAllReturned(): array
    {
        return [
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
    }


    /**
     * Verifies that multiple interrupts all returned.
     *
     * @return void
     */
    public function testMultipleInterruptsAllReturned(): void
    {
        $data = $this->dataForMultipleInterruptsAllReturned();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(2, $agentResponse->interrupts);
        $this->assertSame('deploy', $agentResponse->interrupts[0]->toolName);
        $this->assertSame('scale', $agentResponse->interrupts[1]->toolName);
    }
    /**
     * Data fixture for testMultipleCitationsAllReturned().
     *
     * @return array<string, mixed>
     */
    private function dataForMultipleCitationsAllReturned(): array
    {
        return [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'citationsContent', 'source' => 'url1', 'title' => 'Doc 1'],
                    ['type' => 'text', 'text' => 'some text'],
                    ['type' => 'citationsContent', 'source' => 'url2', 'title' => 'Doc 2'],
                ],
            ],
        ];
    }


    /**
     * Verifies that multiple citations all returned.
     *
     * @return void
     */
    public function testMultipleCitationsAllReturned(): void
    {
        $data = $this->dataForMultipleCitationsAllReturned();

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertCount(2, $agentResponse->citations);
        $this->assertSame('url1', $agentResponse->citations[0]['source']);
        $this->assertSame('url2', $agentResponse->citations[1]['source']);
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

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame([], $agentResponse->citations);
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

        $agentResponse = AgentResponse::fromArray($data);
        $citations = $agentResponse->getCitationObjects();

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
        $data = $this->loadJsonFixture('invoke-response-with-citations.json');

        $agentResponse = AgentResponse::fromArray($data);
        $citations = $agentResponse->getCitationObjects();

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

        $agentResponse = AgentResponse::fromArray($data);
        $first = $agentResponse->getCitationObjects();
        $second = $agentResponse->getCitationObjects();

        $this->assertSame($first, $second);
    }

    /**
     * Verifies that get citation objects returns empty for no citations.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsEmptyForNoCitations(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->getCitationObjects());
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

        $agentResponse = AgentResponse::fromArray($data);
        $structuredOutput = $agentResponse->structuredOutputAs(TestStructuredDto::class);

        $this->assertInstanceOf(TestStructuredDto::class, $structuredOutput);
        $this->assertSame('John', $structuredOutput->name);
        $this->assertSame(30, $structuredOutput->age);
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

        $agentResponse = AgentResponse::fromArray($data);
        $structuredOutput = $agentResponse->structuredOutputAs(TestConstructorDto::class);

        $this->assertInstanceOf(TestConstructorDto::class, $structuredOutput);
        $this->assertSame('Jane', $structuredOutput->name);
        $this->assertSame(95.5, $structuredOutput->score);
    }

    /**
     * Verifies that structured output as throws when null.
     *
     * @return void
     */
    public function testStructuredOutputAsThrowsWhenNull(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No structured output in response');
        $agentResponse->structuredOutputAs(TestStructuredDto::class);
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

        $agentResponse = AgentResponse::fromArray($data);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to hydrate/');
        $agentResponse->structuredOutputAs(TestConstructorDto::class);
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

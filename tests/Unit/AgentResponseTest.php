<?php

/**
 * Exercises the invoke response fields an application renders or uses for control flow.
 * It covers defensive parsing, legacy aliases, citations, guardrails, and structured output.
 * Failures here mean an answer screen could lose data or misread what the agent returned.
 */

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;

/**
 * Verifies invoke responses remain safe and predictable for application code.
 *
 * It protects answer text, conversation continuity, usage, tools, interrupts, citations, and compatibility metadata.
 * Use these scenarios when changing AgentResponse hydration or any public result property.
 */
class AgentResponseTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic response-hydration scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array hydrates all fields behavior stable for app callers.
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
     * Covers "from array hydrates all fields" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array handles missing fields" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array handles empty usage behavior stable for app callers.
     */
    private function dataForFromArrayHandlesEmptyUsage(): array
    {
        return [
            'text' => 'Test',
            'usage' => [],
        ];
    }

    /**
     * Covers "from array handles empty usage" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array filters malformed tools used behavior stable for app callers.
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
     * Covers "from array filters malformed tools used" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "usage default values" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array handles non int usage values behavior stable for app callers.
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
     * Covers "from array handles non int usage values" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array has objective requires strict true" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array strips non int duration ms behavior stable for app callers.
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
     * Covers "from array strips non int duration ms" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array strips extra keys from tools used behavior stable for app callers.
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
     * Covers "from array strips extra keys from tools used" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array parses safe tool summaries" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array hydrates stop reason" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array handles unknown stop reason behavior stable for app callers.
     */
    private function dataForFromArrayHandlesUnknownStopReason(): array
    {
        return [
            'text' => 'Test',
            'stop_reason' => 'unknown_future_reason',
        ];
    }

    /**
     * Covers "from array handles unknown stop reason" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array defaults omitted field to null" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @param string $propertyName Property on AgentResponse expected to be null when omitted.
     * @return void
     */
    #[DataProvider('omittedFieldDefaultsToNullProvider')]
    public function testFromArrayDefaultsOmittedFieldToNull(string $propertyName): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($agentResponse->{$propertyName});
    }

    /**
     * Supplies the input variants for the related response-hydration scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: string}> Scenario data for omitted field defaults to null behavior.
     */
    public static function omittedFieldDefaultsToNullProvider(): iterable
    {
        yield 'stopReason' => ['stopReason'];
        yield 'structuredOutput' => ['structuredOutput'];
    }

    /**
     * Covers "from array hydrates structured output" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array hydrates cache tokens behavior stable for app callers.
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
     * Covers "from array hydrates cache tokens" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array parses usage camel case and rounds float latency" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayParsesUsageCamelCaseAndRoundsFloatLatency(): void
    {
        $expectedLatencyMs = 843;
        $expectedTimeToFirstByteMs = 210;
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
        $this->assertSame($expectedLatencyMs, $agentResponse->usage->latencyMs);
        $this->assertSame($expectedTimeToFirstByteMs, $agentResponse->usage->timeToFirstByteMs);
    }

    /**
     * Covers "snake case usage wins over camel case" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep usage defaults to zero for missing cache fields behavior stable for app callers.
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
     * Covers "usage defaults to zero for missing cache fields" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "total tokens follows documented fallback chain" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @param \Closure(): \StrandsPhpClient\Response\Usage $buildUsage Factory for the Usage instance under test.
     * @param int $expectedTotal Expected return value of totalTokens().
     * @return void
     */
    #[DataProvider('totalTokensProvider')]
    public function testTotalTokensFollowsDocumentedFallbackChain(\Closure $buildUsage, int $expectedTotal): void
    {
        $this->assertSame($expectedTotal, $buildUsage()->totalTokens());
    }

    /**
     * Supplies the input variants for the related response-hydration scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: \Closure(): \StrandsPhpClient\Response\Usage, 1: int}> Scenario data for total tokens behavior.
     */
    public static function totalTokensProvider(): iterable
    {
        yield 'sums input+output when wire totalTokens absent' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50),
            150,
        ];
        yield 'prefers wire totalTokens when present' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50, totalTokens: 160),
            160,
        ];
        yield 'defaults to zero when no fields supplied' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(),
            0,
        ];
    }

    /**
     * Covers "from array captures unknown keys as metadata" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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

        // A newer wrapper field remains available to an older app through the forward-compatible metadata bag.
        $this->assertArrayHasKey('trace_id', $agentResponse->metadata);
        $this->assertSame('abc-123-def', $agentResponse->metadata['trace_id']);
        $this->assertArrayHasKey('model_id', $agentResponse->metadata);
        $this->assertSame('claude-3-sonnet', $agentResponse->metadata['model_id']);
        $this->assertArrayHasKey('request_id', $agentResponse->metadata);
        $this->assertSame('req-456', $agentResponse->metadata['request_id']);
    }

    /**
     * Covers "from array preserves top level wrapper metadata in both access paths" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayPreservesTopLevelWrapperMetadataInBothAccessPaths(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-metadata.json');

        $agentResponse = AgentResponse::fromArray($data);

        $expectedMetadata = ['document_type' => 'referral', 'confidence' => 0.91];

        $this->assertSame($expectedMetadata, $agentResponse->wrapperMetadata);
        $this->assertSame($expectedMetadata, $agentResponse->metadata['metadata']);
    }

    /**
     * Covers "from array preserves nested message metadata" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array parses context size fields" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void The assertions protect apps upgrading from metadata reads to the dedicated context-size properties.
     */
    public function testFromArrayParsesContextSizeFields(): void
    {
        $data = $this->loadJsonFixture('wire-contract/invoke-response-context-size.json');
        $currentContextTokens = 8192;
        $projectedContextTokens = 9216;

        $agentResponse = AgentResponse::fromArray($data);

        $this->assertSame($currentContextTokens, $agentResponse->contextSize);
        $this->assertSame($projectedContextTokens, $agentResponse->projectedContextSize);
        $this->assertSame($currentContextTokens, $agentResponse->metadata['context_size']);
        $this->assertSame($projectedContextTokens, $agentResponse->metadata['projected_context_size']);
    }
    /**
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array metadata empty when no unknown keys behavior stable for app callers.
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
     * Covers "from array metadata empty when no unknown keys" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array metadata excludes known keys behavior stable for app callers.
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
     * Covers "from array metadata excludes known keys" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayMetadataExcludesKnownKeys(): void
    {
        $data = $this->dataForFromArrayMetadataExcludesKnownKeys();

        $agentResponse = AgentResponse::fromArray($data);

        // Canonical answer and session fields stay out of the extension bag so callers have one source of truth.
        $this->assertArrayNotHasKey('text', $agentResponse->metadata);
        $this->assertArrayNotHasKey('session_id', $agentResponse->metadata);
        // The app can still read a wrapper-specific field that has no dedicated DTO property.
        $this->assertSame('custom_value', $agentResponse->metadata['custom_field']);
    }
    /**
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array handles all stop reasons behavior stable for app callers.
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
        ];
    }

    /**
     * Covers "from array handles all stop reasons" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayHandlesAllStopReasons(): void
    {
        $reasons = $this->dataForFromArrayHandlesAllStopReasons();

        // Every 1.4 enum value must still drive the same exhaustive application control flow.
        foreach ($reasons as $rawStopReason => $expectedStopReason) {
            $agentResponse = AgentResponse::fromArray(['text' => 'Test', 'stop_reason' => $rawStopReason]);
            $this->assertSame($expectedStopReason, $agentResponse->stopReason, "Failed for stop_reason: $rawStopReason");
        }
    }

    /**
     * Covers "post v14 stop reasons remain raw only" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testPostV14StopReasonsRemainRawOnly(): void
    {
        $this->assertSame([
            'end_turn',
            'tool_use',
            'max_tokens',
            'stop_sequence',
            'content_filtered',
            'guardrail_intervened',
            'interrupt',
        ], array_map(
            static fn (StopReason $stopReason): string => $stopReason->value,
            StopReason::cases(),
        ));

        // Values added after 1.4 stay available as raw text without expanding the enum and breaking exhaustive match expressions in apps.
        foreach (['error', 'cancelled', 'checkpoint'] as $rawStopReason) {
            $agentResponse = AgentResponse::fromArray(['text' => '', 'stop_reason' => $rawStopReason]);

            $this->assertNull($agentResponse->stopReason, "{$rawStopReason} must not expand the 1.x enum");
            $this->assertSame(
                $rawStopReason,
                $agentResponse->rawStopReason,
                "{$rawStopReason} must remain observable through rawStopReason",
            );
        }
    }

    /**
     * Covers "from array parses interrupts" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array no interrupts defaults empty" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array parses guardrail trace" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array guardrail trace from nested trace behavior stable for app callers.
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
     * Covers "from array guardrail trace from nested trace" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array guardrail trace defaults to null" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceDefaultsToNull(): void
    {
        // Keep this separate from generic null defaults because GuardrailTrace hydration follows its own app-visible parsing path first.
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($agentResponse->guardrailTrace);
    }

    /**
     * Covers "from array parses citations" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "from array citations defaults to empty" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testFromArrayCitationsDefaultsToEmpty(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->citations);
    }
    /**
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep from array citations ignores non citation blocks behavior stable for app callers.
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
     * Covers "from array citations ignores non citation blocks" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep interrupts excluded from metadata behavior stable for app callers.
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
     * Covers "interrupts excluded from metadata" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep guardrail trace excluded from metadata behavior stable for app callers.
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
     * Covers "guardrail trace excluded from metadata" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "has objective default value" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testHasObjectiveDefaultValue(): void
    {
        $agentResponse = new AgentResponse(text: 'Test');

        $this->assertFalse($agentResponse->hasObjective);
    }
    /**
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep multiple interrupts all returned behavior stable for app callers.
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
     * Covers "multiple interrupts all returned" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Builds readable wire data for the related response-hydration scenario.
     * Use it when the matching response case needs a realistic agent payload.
     *
     * @return array<string, mixed> Scenarios that keep multiple citations all returned behavior stable for app callers.
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
     * Covers "multiple citations all returned" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "citations returns empty when message not array" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "get citation objects returns typed list" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "get citation objects preserves flat citation fields" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "get citation objects caches result" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "get citation objects returns empty for no citations" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsEmptyForNoCitations(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->getCitationObjects());
    }

    /**
     * Covers "structured output as with from array factory" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "structured output as with constructor" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "structured output as throws when null" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
     * Covers "structured output as throws on mismatch" so answer screens retain safe fields and 1.x reads.
     * Use this regression case when response parsing or wire compatibility changes.
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
 * Provides a structured-output fixture with its own fromArray() factory.
 *
 * It represents an application DTO used to render a validated answer.
 * Use it to verify AgentResponse prefers a public static factory over constructor spreading.
 *
 * @internal
 */
class TestStructuredDto
{
    /**
     * Creates a fixture DTO used to demonstrate structured-output hydration for an app.
     * Use it only in the matching response-conversion scenario.
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
     * Hydrates a fixture DTO from the structured data returned by the agent.
     * Use it when the test needs to mirror an app-level response factory.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return self New instance ready for app code.
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
 * Provides a structured-output fixture that can be hydrated only through named constructor arguments.
 *
 * It represents an application DTO without a fromArray() factory.
 * Use it to verify constructor hydration and the user-facing mismatch error path.
 *
 * @internal
 */
class TestConstructorDto
{
    /**
     * Creates a fixture DTO used to demonstrate structured-output hydration for an app.
     * Use it only in the matching response-conversion scenario.
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

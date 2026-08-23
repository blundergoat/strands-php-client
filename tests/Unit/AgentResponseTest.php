<?php

/**
 * Covers invoke-response hydration and the two application DTO shapes used to test structured output conversion.
 *
 * Use this file when changing AgentResponse fields, compatibility defaults, or structured-output mapping.
 * It protects the complete result object a caller reads after an agent invocation.
 */

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
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
     * @param string $relativePath Non-empty path relative to tests/Fixtures/.
     * @return array<string, mixed> Decoded fixture fields; never empty in these scenarios.
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
     * Builds a response containing every core field an invoke result exposes.
     * Use it to verify callers receive text, session, usage, tools, and objective state together.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function completeResponsePayload(): array
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
     * Verifies AgentResponse::fromArray() hydrates all fields so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $responseData = $this->completeResponsePayload();

        $agentResponse = AgentResponse::fromArray($responseData);

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
     * Verifies AgentResponse::fromArray() handles missing fields so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $responseData = ['text' => 'Minimal response'];

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame('Minimal response', $agentResponse->text);
        $this->assertNull($agentResponse->agent);
        $this->assertNull($agentResponse->sessionId);
        $this->assertFalse($agentResponse->hasObjective);
        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(0, $agentResponse->usage->outputTokens);
        $this->assertSame([], $agentResponse->toolsUsed);
    }
    /**
     * Builds a response whose usage object is present but empty.
     * Use it to verify callers receive zero token counts instead of missing Usage data.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithEmptyUsage(): array
    {
        return [
            'text' => 'Test',
            'usage' => [],
        ];
    }

    /**
     * Verifies AgentResponse::fromArray() defaults an empty usage block to zero values so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHandlesEmptyUsage(): void
    {
        $responseData = $this->responsePayloadWithEmptyUsage();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(0, $agentResponse->usage->outputTokens);
    }
    /**
     * Builds a response mixing valid tool records with malformed entries.
     * Use it to verify callers receive only tools with usable names.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithMalformedTools(): array
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
     * Verifies AgentResponse::fromArray() filters malformed tool summaries so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayFiltersMalformedToolsUsed(): void
    {
        $responseData = $this->responsePayloadWithMalformedTools();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertSame('search', $agentResponse->toolsUsed[0]['name']);
        $this->assertSame('calculator', $agentResponse->toolsUsed[1]['name']);
    }

    /**
     * Verifies Usage starts with safe zero values so callers can safely read results from 1.x agents.
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
     * Builds a response whose token counts use unsupported scalar and null values.
     * Use it to verify callers receive safe zero counts instead of invalid metrics.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithNonIntegerUsage(): array
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
     * Verifies AgentResponse::fromArray() handles non-integer usage values so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHandlesNonIntUsageValues(): void
    {
        $responseData = $this->responsePayloadWithNonIntegerUsage();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame(0, $agentResponse->usage->inputTokens);
        $this->assertSame(42, $agentResponse->usage->outputTokens);
    }

    /**
     * Verifies AgentResponse::fromArray() treats only literal true as an objective so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHasObjectiveRequiresStrictTrue(): void
    {
        $responseData = [
            'text' => 'Test',
            'has_objective' => 'true',
        ];

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertFalse($agentResponse->hasObjective);
    }
    /**
     * Builds a response whose tool duration is not an integer.
     * Use it to verify the app still receives the tool name without an invalid duration.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithNonIntegerToolDuration(): array
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
     * Verifies AgentResponse::fromArray() drops non-integer tool durations so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayStripsNonIntDurationMs(): void
    {
        $responseData = $this->responsePayloadWithNonIntegerToolDuration();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertArrayNotHasKey('duration_ms', $agentResponse->toolsUsed[0]);
        $this->assertSame(42, $agentResponse->toolsUsed[1]['duration_ms']);
    }
    /**
     * Builds a response whose tool record contains wrapper-specific fields.
     * Use it to verify callers receive the stable name and duration shape only.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithExtraToolFields(): array
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
     * Verifies AgentResponse::fromArray() keeps only stable tool-summary fields so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayStripsExtraKeysFromToolsUsed(): void
    {
        $responseData = $this->responsePayloadWithExtraToolFields();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(2, $agentResponse->toolsUsed);
        $this->assertSame(['name' => 'search', 'duration_ms' => 100], $agentResponse->toolsUsed[0]);
        $this->assertSame(['name' => 'calc'], $agentResponse->toolsUsed[1]);
    }

    /**
     * Verifies AgentResponse::fromArray() parses safe tool summaries so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayParsesSafeToolSummaries(): void
    {
        $responseData = $this->loadJsonFixture('wire-contract/invoke-response-tools-full.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame('availability_lookup', $agentResponse->toolsUsed[0]['name']);
        $this->assertSame(114, $agentResponse->toolsUsed[0]['duration_ms']);
        $this->assertSame(['summary' => 'date lookup'], $agentResponse->toolsUsed[0]['input']);
        $this->assertSame(['summary' => 'slot available'], $agentResponse->toolsUsed[0]['result']);
    }

    /**
     * Verifies AgentResponse::fromArray() hydrates typed and raw stop reasons so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHydratesStopReason(): void
    {
        $responseData = [
            'text' => 'Test',
            'stop_reason' => 'end_turn',
        ];

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame(StopReason::EndTurn, $agentResponse->stopReason);
    }
    /**
     * Builds a response with a stop reason added outside the closed 1.x enum.
     * Use it to verify callers keep the raw value without receiving a false enum case.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithUnknownStopReason(): array
    {
        return [
            'text' => 'Test',
            'stop_reason' => 'unknown_future_reason',
        ];
    }

    /**
     * Verifies AgentResponse::fromArray() preserves an unknown stop reason only as raw text so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHandlesUnknownStopReason(): void
    {
        $responseData = $this->responsePayloadWithUnknownStopReason();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertNull($agentResponse->stopReason);
        $this->assertSame('unknown_future_reason', $agentResponse->rawStopReason);
    }

    /**
     * Verifies AgentResponse::fromArray() defaults each omitted optional field to null so callers can safely read results from 1.x agents.
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
     * Lists optional response properties that remain null when the agent omits them.
     * An empty provider would leave safe result defaults unverified.
     *
     * @return iterable<string, array{0: string}> Optional property names; never empty.
     */
    public static function omittedFieldDefaultsToNullProvider(): iterable
    {
        yield 'stopReason' => ['stopReason'];
        yield 'structuredOutput' => ['structuredOutput'];
    }

    /**
     * Verifies AgentResponse::fromArray() hydrates structured output so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHydratesStructuredOutput(): void
    {
        $structured = ['name' => 'John', 'age' => 30, 'active' => true];
        $responseData = [
            'text' => 'Test',
            'structured_output' => $structured,
        ];

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame($structured, $agentResponse->structuredOutput);
    }
    /**
     * Builds a response with cache and latency metrics in its usage block.
     * Use it to verify callers can display the full supported usage summary.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithCacheUsage(): array
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
     * Verifies AgentResponse::fromArray() hydrates cache tokens so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHydratesCacheTokens(): void
    {
        $responseData = $this->responsePayloadWithCacheUsage();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame(100, $agentResponse->usage->inputTokens);
        $this->assertSame(50, $agentResponse->usage->outputTokens);
        $this->assertSame(80, $agentResponse->usage->cacheReadInputTokens);
        $this->assertSame(20, $agentResponse->usage->cacheWriteInputTokens);
        $this->assertSame(1500, $agentResponse->usage->latencyMs);
        $this->assertSame(200, $agentResponse->usage->timeToFirstByteMs);
    }

    /**
     * Verifies AgentResponse::fromArray() accepts camelCase usage and rounds fractional latency so callers can safely read results from 1.x agents.
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
     * Verifies snake_case usage takes precedence over camelCase compatibility fields so callers can safely read results from 1.x agents.
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
     * Builds a response whose usage block omits cache metrics.
     * Use it to verify legacy responses still produce zero cache counts for callers.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithoutCacheUsage(): array
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
     * Verifies usage defaults to zero for missing cache fields so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testUsageDefaultsToZeroForMissingCacheFields(): void
    {
        $responseData = $this->responsePayloadWithoutCacheUsage();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame(0, $agentResponse->usage->cacheReadInputTokens);
        $this->assertSame(0, $agentResponse->usage->cacheWriteInputTokens);
        $this->assertSame(0, $agentResponse->usage->latencyMs);
        $this->assertSame(0, $agentResponse->usage->timeToFirstByteMs);
    }

    /**
     * Verifies total tokens follows documented fallback chain so callers can safely read results from 1.x agents.
     *
     * @param \Closure(): \StrandsPhpClient\Response\Usage $buildUsage Non-null factory for the Usage instance under test.
     * @param int $expectedTotal Expected return value of totalTokens().
     * @return void
     */
    #[DataProvider('totalTokensProvider')]
    public function testTotalTokensFollowsDocumentedFallbackChain(\Closure $buildUsage, int $expectedTotal): void
    {
        $this->assertSame($expectedTotal, $buildUsage()->totalTokens());
    }

    /**
     * Lists usage combinations and the total token count callers should receive.
     * An empty provider would leave the documented fallback order unverified.
     *
     * @return iterable<string, array{0: \Closure(): \StrandsPhpClient\Response\Usage, 1: int}> Usage factories and expected totals; never empty.
     */
    public static function totalTokensProvider(): iterable
    {
        yield 'sums input+output when wire totalTokens absent' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(inputTokens: 100, outputTokens: 50),
            150,
        ];
        yield 'prefers wire totalTokens when present' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(
                inputTokens: 100,
                outputTokens: 50,
                totalTokens: 160,
            ),
            160,
        ];
        yield 'defaults to zero when no fields supplied' => [
            static fn (): \StrandsPhpClient\Response\Usage => new \StrandsPhpClient\Response\Usage(),
            0,
        ];
    }

    /**
     * Verifies structuredOutputAs() uses a DTO fromArray() factory when available so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testStructuredOutputAsWithFromArrayFactory(): void
    {
        $responseData = [
            'text' => 'Test',
            'structured_output' => ['name' => 'John', 'age' => 30],
        ];

        $agentResponse = AgentResponse::fromArray($responseData);
        $structuredOutput = $agentResponse->structuredOutputAs(TestStructuredDto::class);

        $this->assertInstanceOf(TestStructuredDto::class, $structuredOutput);
        $this->assertSame('John', $structuredOutput->name);
        $this->assertSame(30, $structuredOutput->age);
    }

    /**
     * Verifies structuredOutputAs() falls back to named constructor arguments so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testStructuredOutputAsWithConstructor(): void
    {
        $responseData = [
            'text' => 'Test',
            'structured_output' => ['name' => 'Jane', 'score' => 95.5],
        ];

        $agentResponse = AgentResponse::fromArray($responseData);
        $structuredOutput = $agentResponse->structuredOutputAs(TestConstructorDto::class);

        $this->assertInstanceOf(TestConstructorDto::class, $structuredOutput);
        $this->assertSame('Jane', $structuredOutput->name);
        $this->assertSame(95.5, $structuredOutput->score);
    }

    /**
     * Verifies structuredOutputAs() rejects conversion when no structured output arrived so callers can safely read results from 1.x agents.
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
     * Verifies structuredOutputAs() reports a field mismatch to the caller so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testStructuredOutputAsThrowsOnMismatch(): void
    {
        $responseData = [
            'text' => 'Test',
            'structured_output' => ['wrong_key' => 'value'],
        ];

        $agentResponse = AgentResponse::fromArray($responseData);

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
     * Use it when an application DTO supplies safe defaults through its constructor.
     *
     * @param string $name Name hydrated for the app; empty is the safe fallback when response data omits or mistypes it.
     * @param int $age Age hydrated for the app; zero is the safe fallback when response data omits or mistypes it.
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
     * @param array<string, mixed> $responseData Decoded response; an empty array creates a DTO with safe UI defaults.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $responseData): self
    {
        return new self(
            name: is_string($responseData['name'] ?? null) ? $responseData['name'] : '',
            age: is_int($responseData['age'] ?? null) ? $responseData['age'] : 0,
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
     * Use it when testing named-constructor hydration without a fromArray() factory.
     *
     * @param string $name Name required by constructor hydration; an empty value remains visible to the app.
     * @param float $score Score required by constructor hydration and returned unchanged to the app.
     */
    public function __construct(
        public readonly string $name,
        public readonly float $score,
    ) {
    }
}

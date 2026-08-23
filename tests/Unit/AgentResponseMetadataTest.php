<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\StopReason;

/**
 * Verifies response metadata preserves known fields, future fields, and raw stop reasons for callers.
 *
 * Use these tests when changing compatibility parsing or StopReason handling.
 * They protect application control flow without expanding the closed 1.x enum.
 */
class AgentResponseMetadataTest extends TestCase
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
     * Verifies AgentResponse::fromArray() captures unknown keys as metadata so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayCapturesUnknownKeysAsMetadata(): void
    {
        $responseData = $this->loadJsonFixture('invoke-response-with-metadata.json');

        $agentResponse = AgentResponse::fromArray($responseData);

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
     * Verifies AgentResponse::fromArray() preserves top-level wrapper metadata in both access paths.
     * This keeps result reads safe and compatible throughout 1.x.
     *
     * @return void
     */
    public function testFromArrayPreservesTopLevelWrapperMetadataInBothAccessPaths(): void
    {
        $responseData = $this->loadJsonFixture('wire-contract/invoke-response-metadata.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $expectedMetadata = ['document_type' => 'referral', 'confidence' => 0.91];

        $this->assertSame($expectedMetadata, $agentResponse->wrapperMetadata);
        $this->assertSame($expectedMetadata, $agentResponse->metadata['metadata']);
    }

    /**
     * Verifies AgentResponse::fromArray() preserves nested message metadata so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayPreservesNestedMessageMetadata(): void
    {
        $responseData = $this->loadJsonFixture('wire-contract/invoke-response-message-metadata.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertNotNull($agentResponse->message);
        $this->assertSame('assistant', $agentResponse->message->role);
        $this->assertNotNull($agentResponse->message->metadata);
        $this->assertSame(410, $agentResponse->message->metadata->usage?->inputTokens);
        $this->assertSame(842.5, $agentResponse->message->metadata->metrics['latency_ms']);
        $this->assertSame('referral', $agentResponse->message->metadata->custom['document_type']);
    }

    /**
     * Verifies AgentResponse::fromArray() parses context size fields so callers can safely read results from 1.x agents.
     *
     * @return void The assertions protect apps upgrading from metadata reads to the dedicated context-size properties.
     */
    public function testFromArrayParsesContextSizeFields(): void
    {
        $responseData = $this->loadJsonFixture('wire-contract/invoke-response-context-size.json');
        $currentContextTokens = 8192;
        $projectedContextTokens = 9216;

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame($currentContextTokens, $agentResponse->contextSize);
        $this->assertSame($projectedContextTokens, $agentResponse->projectedContextSize);
        $this->assertSame($currentContextTokens, $agentResponse->metadata['context_size']);
        $this->assertSame($projectedContextTokens, $agentResponse->metadata['projected_context_size']);
    }

    /**
     * Builds a response containing only fields the client already models.
     * Use it to verify metadata stays empty instead of duplicating typed fields.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithoutUnknownMetadata(): array
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
     * Verifies AgentResponse::fromArray() leaves metadata empty when every field is modeled so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayMetadataEmptyWhenNoUnknownKeys(): void
    {
        $responseData = $this->responsePayloadWithoutUnknownMetadata();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame([], $agentResponse->metadata);
    }

    /**
     * Builds a response with one typed field and one future wrapper field.
     * Use it to verify callers find only the unmodelled field in metadata.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithCustomMetadata(): array
    {
        return [
            'text' => 'Test',
            'session_id' => 's1',
            'custom_field' => 'custom_value',
        ];
    }

    /**
     * Verifies AgentResponse::fromArray() excludes modeled fields from compatibility metadata so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayMetadataExcludesKnownKeys(): void
    {
        $responseData = $this->responsePayloadWithCustomMetadata();

        $agentResponse = AgentResponse::fromArray($responseData);

        // Canonical answer and session fields stay out of the extension bag so callers have one source of truth.
        $this->assertArrayNotHasKey('text', $agentResponse->metadata);
        $this->assertArrayNotHasKey('session_id', $agentResponse->metadata);
        // The app can still read a wrapper-specific field that has no dedicated DTO property.
        $this->assertSame('custom_value', $agentResponse->metadata['custom_field']);
    }

    /**
     * Lists every raw stop reason represented by the closed 1.x enum.
     * Use it to verify application control flow receives the matching StopReason case.
     *
     * @return array<string, StopReason> Raw wire values mapped to caller-visible enum cases; never empty.
     */
    private function supportedStopReasonCases(): array
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
     * Verifies AgentResponse::fromArray() handles all stop reasons so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayHandlesAllStopReasons(): void
    {
        $reasons = $this->supportedStopReasonCases();

        // Every 1.4 enum value must still drive the same exhaustive application control flow.
        foreach ($reasons as $rawStopReason => $expectedStopReason) {
            $agentResponse = AgentResponse::fromArray(['text' => 'Test', 'stop_reason' => $rawStopReason]);
            $this->assertSame($expectedStopReason, $agentResponse->stopReason, "Failed for stop_reason: $rawStopReason");
        }
    }

    /**
     * Verifies post-1.4 stop reasons remain available only as raw values so callers can safely read results from 1.x agents.
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
     * Builds a response with typed interrupts and an unrelated custom field.
     * Use it to verify metadata retains only the custom extension for callers.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithInterruptMetadata(): array
    {
        return [
            'text' => 'Test',
            'interrupts' => [],
            'custom' => 'value',
        ];
    }

    /**
     * Verifies typed interrupts stay out of compatibility metadata so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testInterruptsExcludedFromMetadata(): void
    {
        $responseData = $this->responsePayloadWithInterruptMetadata();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertArrayNotHasKey('interrupts', $agentResponse->metadata);
        $this->assertSame('value', $agentResponse->metadata['custom']);
    }

    /**
     * Builds a response with guardrail fields in each supported wire location.
     * Use it to verify safety data hydrates once and stays out of compatibility metadata.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithGuardrailMetadata(): array
    {
        return [
            'text' => 'Test',
            'guardrail_trace' => ['action' => 'NONE'],
            'trace' => ['guardrail' => ['action' => 'NONE']],
            'message' => ['content' => []],
        ];
    }

    /**
     * Verifies typed guardrail details stay out of compatibility metadata so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testGuardrailTraceExcludedFromMetadata(): void
    {
        $responseData = $this->responsePayloadWithGuardrailMetadata();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertArrayNotHasKey('guardrail_trace', $agentResponse->metadata);
        $this->assertArrayNotHasKey('trace', $agentResponse->metadata);
        $this->assertArrayNotHasKey('message', $agentResponse->metadata);
    }

    /**
     * Verifies hasObjective defaults to false when omitted so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testHasObjectiveDefaultValue(): void
    {
        $agentResponse = new AgentResponse(text: 'Test');

        $this->assertFalse($agentResponse->hasObjective);
    }

}

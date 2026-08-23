<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies complete SSE events hydrate final text, usage, tools, interrupts, and guardrail details.
 *
 * Use these tests when changing terminal event parsing or StreamResult accumulation.
 * They protect the final state an application renders after live updates stop.
 */
class StreamParserCompletionTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic stream-parsing scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $fixtureName Non-empty SSE fixture filename under tests/Fixtures/.
     * @return string Captured SSE bytes; empty means the parser receives no event.
     */
    private function loadFixture(string $fixtureName): string
    {
        $fixturePath = __DIR__ . '/../Fixtures/' . $fixtureName;

        return file_get_contents($fixturePath);
    }

    /**
     * Verifies the parser identifies terminal events, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testTerminalEventDetection(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-simple-text.txt');

        $events = $streamParser->feed($sseFrame);

        $this->assertFalse($events[0]->isTerminal());
        $this->assertFalse($events[1]->isTerminal());
        $this->assertTrue($events[2]->isTerminal());
    }

    /**
     * Builds a completion frame containing two tool summaries.
     * Use it to verify the final app result preserves each tool's supported details.
     *
     * @return string Complete SSE frame; never empty.
     */
    private function completionSseFrameWithMultipleTools(): string
    {
        return 'data: {"type": "complete", "text": "Result", "session_id": "s1", "usage": {}, '
            . '"tools_used": [{"name": "search", "duration_ms": 100, '
            . '"input": {"query": "docs"}, "result": {"count": 2}}, '
            . '{"name": "calc", "duration_ms": 50}]}' . "\n\n";
    }

    /**
     * Verifies complete events preserve every valid tool summary.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testCompleteEventWithMultipleToolsUsed(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->completionSseFrameWithMultipleTools();

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertCount(2, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
        $this->assertSame(100, $events[0]->toolsUsed[0]['duration_ms']);
        $this->assertSame(['query' => 'docs'], $events[0]->toolsUsed[0]['input']);
        $this->assertSame(['count' => 2], $events[0]->toolsUsed[0]['result']);
        $this->assertSame('calc', $events[0]->toolsUsed[1]['name']);
        $this->assertSame(50, $events[0]->toolsUsed[1]['duration_ms']);
    }

    /**
     * Verifies feed() preserves a literal true hasObjective flag, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testFeedSetsHasObjectiveFlagWhenTrue(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": true}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->hasObjective);
    }

    /**
     * Verifies hasObjective defaults to false for non-boolean values.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testHasObjectiveDefaultsFalseForNonBooleanValues(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": \"true\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->hasObjective);
    }

    /**
     * Verifies citation events preserve their source data, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testCitationEventParsed(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"citation\", \"citation\": {\"source\": \"doc.pdf\", \"page\": 3, \"text\": \"relevant excerpt\"}}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertSame(['source' => 'doc.pdf', 'page' => 3, 'text' => 'relevant excerpt'], $events[0]->citation);
    }

    /**
     * Verifies reasoning-signature events preserve their signature, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testReasoningSignatureEventParsed(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"reasoning_signature\", \"signature\": \"abc123def456\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningSignature, $events[0]->type);
        $this->assertSame('abc123def456', $events[0]->reasoningSignature);
    }

    /**
     * Verifies redacted-reasoning events preserve their marker, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testReasoningRedactedEventParsed(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"reasoning_redacted\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningRedacted, $events[0]->type);
    }

    /**
     * Verifies StreamEvent::tryFromArray() returns a complete event, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testTryFromArrayReturnsCompleteEvent(): void
    {
        $result = \StrandsPhpClient\Streaming\StreamEvent::tryFromArray([
            'type' => 'complete',
            'text' => 'Full response',
            'session_id' => 'sess-1',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'tools_used' => [],
            'stop_reason' => 'end_turn',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(StreamEventType::Complete, $result->type);
        $this->assertSame('Full response', $result->fullText);
        $this->assertSame('sess-1', $result->sessionId);
        $this->assertSame('end_turn', $result->stopReason);
    }

    /**
     * Verifies complete events preserve their stop reason, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testCompleteEventParsesStopReason(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "complete", "text": "Done", "session_id": "s-1", '
            . '"usage": {}, "tools_used": [], "stop_reason": "end_turn"}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertSame('end_turn', $events[0]->stopReason);
    }

    /**
     * Verifies complete events preserve context-size fields.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testCompleteEventParsesContextSizeFields(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"complete\", \"text\": \"Done\", \"context_size\": 8192, \"projected_context_size\": 9216}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(8192, $events[0]->contextSize);
        $this->assertSame(9216, $events[0]->projectedContextSize);
    }

    /**
     * Verifies hasObjective defaults to false when omitted.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testHasObjectiveDefaultsFalseWhenMissing(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertFalse($events[0]->hasObjective);
    }

    /**
     * Verifies StreamEvent defaults hasObjective to false.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testStreamEventConstructorDefaultsFalseForHasObjective(): void
    {
        $streamEvent = new \StrandsPhpClient\Streaming\StreamEvent(
            type: StreamEventType::Text,
            text: 'hello',
        );

        $this->assertFalse($streamEvent->hasObjective);
    }

    /**
     * Builds a completion frame mixing one valid tool with malformed entries.
     * Use it to verify the final app result excludes tool data it cannot safely render.
     *
     * @return string Complete SSE frame; never empty.
     */
    private function completionSseFrameWithMalformedTools(): string
    {
        return 'data: {"type": "complete", "text": "Done", "session_id": null, "usage": {}, '
            . '"tools_used": [{"name": "search", "duration_ms": 100}, '
            . '{"no_name": true}, "not_array", {"name": 123}]}' . "\n\n";
    }

    /**
     * Verifies terminal tool summaries exclude malformed entries, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testToolsUsedFiltersMalformedEntries(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->completionSseFrameWithMalformedTools();

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        // Only the named tool can become a trustworthy activity entry under the user's answer.
        $this->assertCount(1, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
    }

    /**
     * Verifies complete events preserve every pending interrupt, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testMultipleInterruptsInCompleteEvent(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], "stop_reason": "interrupt", '
            . '"interrupts": [{"tool_name": "deploy", "interrupt_id": "i1", "reason": "Approve"}, '
            . '{"tool_name": "scale", "interrupt_id": "i2", "reason": "Confirm"}]}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertCount(2, $events[0]->interrupts);
        $this->assertSame('deploy', $events[0]->interrupts[0]['tool_name']);
        $this->assertSame('scale', $events[0]->interrupts[1]['tool_name']);
    }

    /**
     * Verifies complete events read guardrail details from nested trace data, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testGuardrailTraceFromNestedTraceKey(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], '
            . '"trace": {"guardrail": {"action": "BLOCKED", "guardrail_id": "g1"}}}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]->guardrailTrace);
        $this->assertSame('BLOCKED', $events[0]->guardrailTrace['action']);
        $this->assertSame('g1', $events[0]->guardrailTrace['guardrail_id']);
    }

    /**
     * Verifies top-level guardrail details take precedence.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testGuardrailTraceTopLevelTakesPrecedence(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], "guardrail_trace": {"action": "TOP"}, '
            . '"trace": {"guardrail": {"action": "NESTED"}}}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame('TOP', $events[0]->guardrailTrace['action']);
    }

    /**
     * Verifies malformed guardrail trace data becomes null.
     * This keeps terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testGuardrailTraceNullWhenTraceKeyIsNotArray(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "trace": "not_array"}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->guardrailTrace);
    }

    /**
     * Verifies citation events preserve their source data correctly, keeping terminal result data complete and safe for callers.
     *
     * @return void
     */
    public function testCitationEventParsedCorrectly(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = 'data: {"type": "citation", "citation": {"source": "doc1", "text": "relevant passage"}}' . "\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertNotNull($events[0]->citation);
        $this->assertSame('doc1', $events[0]->citation['source']);
    }
}

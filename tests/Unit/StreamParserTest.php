<?php

declare(strict_types=1);

/**
 * Exercises raw SSE chunks before typed events reach an application's live callback.
 *
 * It covers framing, line endings, partial delivery, malformed JSON, and future event types.
 * Failures here mean a live UI could lose, duplicate, or misclassify an agent update.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies StreamParser converts unreliable network chunk boundaries into stable typed events.
 *
 * It protects live app updates while tolerating heartbeats, malformed frames, and newer server events.
 * Use these scenarios whenever shared SSE framing or StreamEvent hydration changes.
 */
class StreamParserTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic stream-parsing scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $name Fixture name or DTO name under test.
     * @return string String value produced by the helper.
     */
    private function loadFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Protects "parse simple text stream" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseSimpleTextStream(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text.txt');

        $events = $streamParser->feed($raw);

        $this->assertCount(3, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello, ', $events[0]->text);
        $this->assertSame(StreamEventType::Text, $events[1]->type);
        $this->assertSame('world!', $events[1]->text);
        $this->assertSame(StreamEventType::Complete, $events[2]->type);
        $this->assertSame('Hello, world!', $events[2]->fullText);
        $this->assertSame('test-001', $events[2]->sessionId);
    }

    /**
     * Protects "parse crlf delimited stream" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseCrlfDelimitedStream(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text-crlf.txt');

        $events = $streamParser->feed($raw);

        $this->assertCount(3, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello, ', $events[0]->text);
        $this->assertSame(StreamEventType::Text, $events[1]->type);
        $this->assertSame('world!', $events[1]->text);
        $this->assertSame(StreamEventType::Complete, $events[2]->type);
        $this->assertSame('Hello, world!', $events[2]->fullText);
        $this->assertSame('test-crlf', $events[2]->sessionId);
    }

    /**
     * Protects "parse crlf split across chunks" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseCrlfSplitAcrossChunks(): void
    {
        $streamParser = new StreamParser();

        $firstEvents = $streamParser->feed("data: {\"type\": \"text\",\r");
        $events = $streamParser->feed("\ndata: \"content\": \"hello\"}\r\n\r\n");

        $this->assertSame([], $firstEvents);
        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
        $this->assertSame(0, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "skips heartbeat comments" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkipsHeartbeatComments(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-with-heartbeat.txt');

        $events = $streamParser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Processing...', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
    }

    /**
     * Protects "error mid stream" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testErrorMidStream(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-error-mid-stream.txt');

        $events = $streamParser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
        $this->assertSame('INTERNAL', $events[1]->errorCode);
        $this->assertSame('Model rate limited', $events[1]->errorMessage);
    }

    /**
     * Protects "incremental chunks" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testIncrementalChunks(): void
    {
        $streamParser = new StreamParser();

        // This frame is split mid-payload to reproduce a proxy delivering the user's live update across callbacks.
        $raw = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n";

        // The first partial callback must stay hidden because the user cannot render an incomplete event.
        $events1 = $streamParser->feed(substr($raw, 0, 20));
        $this->assertCount(0, $events1);

        $events2 = $streamParser->feed(substr($raw, 20));
        $this->assertCount(1, $events2);
        $this->assertSame('Hi', $events2[0]->text);
    }

    /**
     * Protects "terminal event detection" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testTerminalEventDetection(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text.txt');

        $events = $streamParser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
        $this->assertFalse($events[1]->isTerminal());
        $this->assertTrue($events[2]->isTerminal());
    }

    /**
     * Protects "empty chunk returns no events" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testEmptyChunkReturnsNoEvents(): void
    {
        $streamParser = new StreamParser();

        $events = $streamParser->feed('');

        $this->assertSame([], $events);
    }
    /**
     * Builds a complete SSE frame for the related live-response scenario.
     * Use it when the parser case needs realistic data without hiding the expected event.
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForParseToolUseEvent(): string
    {
        return "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n";
    }

    /**
     * Protects "parse tool use event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseToolUseEvent(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->rawForParseToolUseEvent();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolUse, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame(['query' => 'test'], $events[0]->toolInput);
    }
    /**
     * Builds a complete SSE frame for the related live-response scenario.
     * Use it when the parser case needs realistic data without hiding the expected event.
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForParseToolResultEvent(): string
    {
        return "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"some results\"}\n\n";
    }

    /**
     * Protects "parse tool result event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseToolResultEvent(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->rawForParseToolResultEvent();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame('some results', $events[0]->toolResult);
    }

    /**
     * Protects "parse thinking event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testParseThinkingEvent(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"thinking\", \"content\": \"Let me reason about this...\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Thinking, $events[0]->type);
        $this->assertSame('Let me reason about this...', $events[0]->text);
    }

    /**
     * Protects "tool use is not terminal" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testToolUseIsNotTerminal(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"tool_use\", \"tool_name\": \"search\", \"tool_input\": {}}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
    }

    /**
     * Protects "thinking is not terminal" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testThinkingIsNotTerminal(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"thinking\", \"content\": \"hmm\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
    }

    /**
     * Protects "tool result with json result" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testToolResultWithJsonResult(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"tool_result\", \"tool_name\": \"api\", \"result\": {\"count\": 42}}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('{"count":42}', $events[0]->toolResult);
    }

    /**
     * Protects "skips unknown event types" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkipsUnknownEventTypes(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"internal_debug\", \"content\": \"something\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "stream event from array throws on unknown type" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testStreamEventFromArrayThrowsOnUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stream event type: "unknown_type"');

        \StrandsPhpClient\Streaming\StreamEvent::fromArray(['type' => 'unknown_type']);
    }

    /**
     * Protects "stream event from array throws on missing type" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testStreamEventFromArrayThrowsOnMissingType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stream event type: "(missing)"');

        \StrandsPhpClient\Streaming\StreamEvent::fromArray(['type' => '']);
    }

    /**
     * Protects "skips event with missing type field" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkipsEventWithMissingTypeField(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"content\": \"no type field\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "skips malformed json without corrupting buffer" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkipsMalformedJsonWithoutCorruptingBuffer(): void
    {
        $streamParser = new StreamParser();

        // A damaged frame followed by a valid update reproduces a stream that recovers without losing later user content.
        $raw = "data: {malformed json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($raw);

        // The user sees the valid update and never receives the malformed frame.
        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Protects "buffer recovery after malformed json" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testBufferRecoveryAfterMalformedJson(): void
    {
        $streamParser = new StreamParser();

        // The first broken frame is skipped instead of poisoning parser state for the rest of the live answer.
        $events1 = $streamParser->feed("data: {broken\n\n");
        $this->assertCount(0, $events1);

        // A later valid frame proves the user can continue receiving updates after that parser error.
        $events2 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"recovered\"}\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('recovered', $events2[0]->text);
    }
    /**
     * Builds a complete SSE frame for the related live-response scenario.
     * Use it when the parser case needs realistic data without hiding the expected event.
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForCompleteEventWithMultipleToolsUsed(): string
    {
        return 'data: {"type": "complete", "text": "Result", "session_id": "s1", "usage": {}, '
            . '"tools_used": [{"name": "search", "duration_ms": 100, '
            . '"input": {"query": "docs"}, "result": {"count": 2}}, '
            . '{"name": "calc", "duration_ms": 50}]}' . "\n\n";
    }

    /**
     * Protects "complete event with multiple tools used" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCompleteEventWithMultipleToolsUsed(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->rawForCompleteEventWithMultipleToolsUsed();

        $events = $streamParser->feed($raw);

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
     * Builds a complete SSE frame for the related live-response scenario.
     * Use it when the parser case needs realistic data without hiding the expected event.
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForMultipleDataLinesJoinedWithNewline(): string
    {
        return "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";
    }

    /**
     * Protects "multiple data lines joined with newline" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testMultipleDataLinesJoinedWithNewline(): void
    {
        $streamParser = new StreamParser();
        // A wrapper may split one JSON object across data lines; the UI still receives one decoded event.
        $raw = $this->rawForMultipleDataLinesJoinedWithNewline();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Protects "skipped events counter tracks parse errors" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkippedEventsCounterTracksParseErrors(): void
    {
        $streamParser = new StreamParser();
        $this->assertSame(0, $streamParser->getSkippedEvents());

        // Two broken frames followed by a valid update let the app report accurate compatibility diagnostics without disrupting the user.
        $raw = "data: {bad1\n\n"
            . "data: {bad2\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(2, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "feed sets has objective flag when true" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testFeedSetsHasObjectiveFlagWhenTrue(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": true}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->hasObjective);
    }

    /**
     * Protects "has objective defaults false for non boolean values" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testHasObjectiveDefaultsFalseForNonBooleanValues(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": \"true\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->hasObjective);
    }

    /**
     * Protects "citation event parsed" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCitationEventParsed(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"citation\", \"citation\": {\"source\": \"doc.pdf\", \"page\": 3, \"text\": \"relevant excerpt\"}}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertSame(['source' => 'doc.pdf', 'page' => 3, 'text' => 'relevant excerpt'], $events[0]->citation);
    }

    /**
     * Protects "reasoning signature event parsed" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testReasoningSignatureEventParsed(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"reasoning_signature\", \"signature\": \"abc123def456\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningSignature, $events[0]->type);
        $this->assertSame('abc123def456', $events[0]->reasoningSignature);
    }

    /**
     * Protects "reasoning redacted event parsed" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testReasoningRedactedEventParsed(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"reasoning_redacted\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningRedacted, $events[0]->type);
    }

    /**
     * Protects "buffer overflow throws stream interrupted exception" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testBufferOverflowThrowsStreamInterruptedException(): void
    {
        $streamParser = new StreamParser();

        // Eleven one-megabyte chunks without a delimiter reproduce a wrapper that could otherwise exhaust the user's PHP process.
        $chunk = str_repeat('x', 1024 * 1024);

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('SSE buffer exceeded');

        // Repeated unfinished chunks cross the safety limit before any live update can reach the user.
        for ($chunkIndex = 0; $chunkIndex < 11; $chunkIndex++) {
            $streamParser->feed($chunk);
        }
    }

    /**
     * Protects "buffer does not throw below limit" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testBufferDoesNotThrowBelowLimit(): void
    {
        $streamParser = new StreamParser();

        // Nine megabytes without a delimiter stays below the safety cap, so the user's stream remains open.
        $chunk = str_repeat('x', 1024 * 1024);
        // Repeated unfinished chunks remain safe while their total stays below the limit.
        for ($chunkIndex = 0; $chunkIndex < 9; $chunkIndex++) {
            $streamParser->feed($chunk);
        }

        // No frame finished or failed parsing, so compatibility diagnostics remain at zero.
        $this->assertSame(0, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "large chunk with bounded frames does not trigger buffer limit" so network chunks cannot corrupt the live event sequence.
     *
     * @return void The assertions protect proxies that coalesce multiple sub-10 MB events into one network callback.
     */
    public function testLargeChunkWithBoundedFramesDoesNotTriggerBufferLimit(): void
    {
        $streamParser = new StreamParser();
        $boundedHeartbeatFrame = ':' . str_repeat('x', 6 * 1024 * 1024) . "\n\n";

        $events = $streamParser->feed($boundedHeartbeatFrame . $boundedHeartbeatFrame);

        $this->assertSame([], $events);
        $this->assertSame(0, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "try from array returns null for unparseable event shape" so network chunks cannot corrupt the live event sequence.
     *
     * @param array<string, mixed> $payload Wire-shape payload that cannot resolve to a known event type.
     * @return void
     */
    #[DataProvider('unparseableEventPayloadProvider')]
    public function testTryFromArrayReturnsNullForUnparseableEventShape(array $payload): void
    {
        $this->assertNull(\StrandsPhpClient\Streaming\StreamEvent::tryFromArray($payload));
    }

    /**
     * Supplies the input variants for the related stream-parsing scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: array<string, mixed>}> Malformed stream payloads that should not break live app updates.
     */
    public static function unparseableEventPayloadProvider(): iterable
    {
        yield 'unknown type enum' => [['type' => 'future_event', 'data' => 'something new']];
        yield 'type field missing entirely' => [['content' => 'no type']];
        yield 'type field present but empty string' => [['type' => '']];
    }

    /**
     * Protects "try from array returns event on known type" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testTryFromArrayReturnsEventOnKnownType(): void
    {
        $result = \StrandsPhpClient\Streaming\StreamEvent::tryFromArray([
            'type' => 'text',
            'content' => 'hello',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(StreamEventType::Text, $result->type);
        $this->assertSame('hello', $result->text);
    }

    /**
     * Protects "try from array returns complete event" so network chunks cannot corrupt the live event sequence.
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
     * Protects "skips unknown event in fixture stream" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testSkipsUnknownEventInFixtureStream(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-with-unknown-event.txt');

        $events = $streamParser->feed($raw);

        // A future event is hidden while the recognized text and completion still reach an older app.
        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Protects "complete event parses stop reason" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCompleteEventParsesStopReason(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "Done", "session_id": "s-1", '
            . '"usage": {}, "tools_used": [], "stop_reason": "end_turn"}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertSame('end_turn', $events[0]->stopReason);
    }

    /**
     * Protects "complete event parses context size fields" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCompleteEventParsesContextSizeFields(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Done\", \"context_size\": 8192, \"projected_context_size\": 9216}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(8192, $events[0]->contextSize);
        $this->assertSame(9216, $events[0]->projectedContextSize);
    }

    /**
     * Protects "data with space vs without space parses differently" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testDataWithSpaceVsWithoutSpaceParsesDifferently(): void
    {
        $streamParser = new StreamParser();

        // The common data-plus-space form removes the prefix before JSON reaches the user's callback.
        $raw1 = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";
        $events1 = $streamParser->feed($raw1);

        $this->assertCount(1, $events1);
        $this->assertSame('hello', $events1[0]->text);

        // The valid no-space form removes only data:, preserving the JSON content that follows immediately.
        $noSpaceStreamParser = new StreamParser();
        $raw2 = "data:{\"type\": \"text\", \"content\": \"world\"}\n\n";
        $events2 = $noSpaceStreamParser->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('world', $events2[0]->text);
    }

    /**
     * Protects "comment line continues parsing remaining lines" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCommentLineContinuesParsingRemainingLines(): void
    {
        $streamParser = new StreamParser();

        // Heartbeat comments between data lines are ignored, allowing the user's one logical event to continue across them.
        $raw = ": first comment\n"
            . "data: {\"type\": \"text\",\n"
            . ": middle comment\n"
            . "data:  \"content\": \"multi-line\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('multi-line', $events[0]->text);
    }

    /**
     * Protects "empty data block returns no event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testEmptyDataBlockReturnsNoEvent(): void
    {
        $streamParser = new StreamParser();

        // A heartbeat-only frame carries no user-visible content, while the next data frame does.
        $raw = ": just a heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"after\"}\n\n";

        $events = $streamParser->feed($raw);

        // Only the real data frame reaches the app callback.
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    /**
     * Protects "crlf normalization required" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCrlfNormalizationRequired(): void
    {
        $streamParser = new StreamParser();

        // A Windows-style CRLF delimiter must finish the same user event as LF.
        $raw = "data: {\"type\": \"text\", \"content\": \"crlf\"}\r\n\r\n";
        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('crlf', $events[0]->text);

        // A bare CR delimiter is also valid SSE and must finish one event.
        $bareCrStreamParser = new StreamParser();
        $raw2 = "data: {\"type\": \"text\", \"content\": \"cr\"}\r\r";
        $events2 = $bareCrStreamParser->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('cr', $events2[0]->text);
    }

    /**
     * Protects "buffer advancement after event parsed" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testBufferAdvancementAfterEventParsed(): void
    {
        $streamParser = new StreamParser();

        // Advancing past the first delimiter ensures both consecutive updates reach the user's live screen in order.
        $raw = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame('A', $events[0]->text);
        $this->assertSame('B', $events[1]->text);
    }

    /**
     * Protects "has objective defaults false when missing" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testHasObjectiveDefaultsFalseWhenMissing(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertFalse($events[0]->hasObjective);
    }

    /**
     * Protects "stream event constructor defaults false for has objective" so network chunks cannot corrupt the live event sequence.
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
     * Builds a complete SSE frame for the related live-response scenario.
     * Use it when the parser case needs realistic data without hiding the expected event.
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForToolsUsedFiltersMalformedEntries(): string
    {
        return 'data: {"type": "complete", "text": "Done", "session_id": null, "usage": {}, '
            . '"tools_used": [{"name": "search", "duration_ms": 100}, '
            . '{"no_name": true}, "not_array", {"name": 123}]}' . "\n\n";
    }

    /**
     * Protects "tools used filters malformed entries" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testToolsUsedFiltersMalformedEntries(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->rawForToolsUsedFiltersMalformedEntries();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        // Only the named tool can become a trustworthy activity entry under the user's answer.
        $this->assertCount(1, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
    }

    /**
     * Protects "multiple interrupts in complete event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testMultipleInterruptsInCompleteEvent(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], "stop_reason": "interrupt", '
            . '"interrupts": [{"tool_name": "deploy", "interrupt_id": "i1", "reason": "Approve"}, '
            . '{"tool_name": "scale", "interrupt_id": "i2", "reason": "Confirm"}]}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertCount(2, $events[0]->interrupts);
        $this->assertSame('deploy', $events[0]->interrupts[0]['tool_name']);
        $this->assertSame('scale', $events[0]->interrupts[1]['tool_name']);
    }

    /**
     * Protects "guardrail trace from nested trace key" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testGuardrailTraceFromNestedTraceKey(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], '
            . '"trace": {"guardrail": {"action": "BLOCKED", "guardrail_id": "g1"}}}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]->guardrailTrace);
        $this->assertSame('BLOCKED', $events[0]->guardrailTrace['action']);
        $this->assertSame('g1', $events[0]->guardrailTrace['guardrail_id']);
    }

    /**
     * Protects "guardrail trace top level takes precedence" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testGuardrailTraceTopLevelTakesPrecedence(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, '
            . '"tools_used": [], "guardrail_trace": {"action": "TOP"}, '
            . '"trace": {"guardrail": {"action": "NESTED"}}}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('TOP', $events[0]->guardrailTrace['action']);
    }

    /**
     * Protects "guardrail trace null when trace key is not array" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testGuardrailTraceNullWhenTraceKeyIsNotArray(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "trace": "not_array"}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->guardrailTrace);
    }

    /**
     * Protects "citation event parsed correctly" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCitationEventParsedCorrectly(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "citation", "citation": {"source": "doc1", "text": "relevant passage"}}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertNotNull($events[0]->citation);
        $this->assertSame('doc1', $events[0]->citation['source']);
    }

    /**
     * Protects "crlf split across chunks" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testCrlfSplitAcrossChunks(): void
    {
        $streamParser = new StreamParser();

        // A CR/LF pair split across callbacks is one line ending, not the blank line that would emit a premature UI event.
        $events1 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"split\"}\r");
        $this->assertCount(0, $events1, 'Trailing \\r should not close the event');

        $events2 = $streamParser->feed("\n\r\n");
        $this->assertCount(1, $events2, '\\r\\n split across chunks must normalise to one \\n');
        $this->assertSame('split', $events2[0]->text);
    }

    /**
     * Protects "bare trailing cr normalised without following lf" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testBareTrailingCrNormalisedWithoutFollowingLf(): void
    {
        $streamParser = new StreamParser();

        // A bare trailing CR starts one valid line ending but does not yet provide the blank line that finishes the user's event.
        $events1 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"bare\"}\r");
        $this->assertCount(0, $events1);

        // A second bare CR supplies the blank line and releases the complete update to the UI.
        $events2 = $streamParser->feed("\r");
        $this->assertCount(1, $events2);
        $this->assertSame('bare', $events2[0]->text);
    }

    /**
     * Protects "partial event at eof remains in buffer" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testPartialEventAtEofRemainsInBuffer(): void
    {
        $streamParser = new StreamParser();

        // A partial frame at this callback boundary must remain hidden from the user.
        $events = $streamParser->feed('data: {"type": "text", "content": "partial"}');
        $this->assertCount(0, $events, 'Partial event without \\n\\n must not emit');

        // The later delimiter releases exactly that buffered event to the callback.
        $events2 = $streamParser->feed("\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('partial', $events2[0]->text);
    }

    /**
     * Protects "trailing newline after last event does not create phantom event" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testTrailingNewlineAfterLastEventDoesNotCreatePhantomEvent(): void
    {
        $streamParser = new StreamParser();

        // One trailing newline after a complete event is only partial framing and must not create a phantom UI update.
        $events = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"ok\"}\n\n\n");
        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
    }

    /**
     * Protects "consecutive empty event boundaries skipped" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testConsecutiveEmptyEventBoundariesSkipped(): void
    {
        $streamParser = new StreamParser();

        // An empty frame before valid data reproduces extra delimiters emitted by a permissive wrapper.
        $events = $streamParser->feed("\n\ndata: {\"type\": \"text\", \"content\": \"after\"}\n\n");

        // The empty frame stays hidden and only the real update reaches the user.
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    /**
     * Protects "stream sse eof mid event is discarded" so network chunks cannot corrupt the live event sequence.
     *
     * @return void
     */
    public function testStreamSseEofMidEventIsDiscarded(): void
    {
        // An EOF before the blank-line terminator leaves an incomplete event that streamSse() must never deliver to the app callback.
        $streamParser = new StreamParser();

        // One complete update followed by a truncated frame reproduces an EOF in the middle of the next event.
        $events = $streamParser->feed(
            "data: {\"type\": \"text\", \"content\": \"complete\"}\n\n"
            . 'data: {"type": "text", "content": "incom',
        );

        // The user receives only the event that had a terminating blank line.
        $this->assertCount(1, $events);
        $this->assertSame('complete', $events[0]->text);

        // With no later callback, the unfinished bytes remain internal and are never exposed as a false update.
    }
}

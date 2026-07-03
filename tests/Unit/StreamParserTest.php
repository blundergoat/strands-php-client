<?php

declare(strict_types=1);

/**
 * Tests caller-visible Stream Parser behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies Stream Parser behavior that application users rely on.
 */
class StreamParserTest extends TestCase
{
    /**
     * Load fixture for the test scenario.
     *
     * @param string $name Fixture name or DTO name under test.
     * @return string String value produced by the helper.
     */
    private function loadFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Verifies that parse simple text stream.
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
     * Verifies that parse crlf delimited stream.
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
     * Verifies that skips heartbeat comments.
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
     * Verifies that error mid stream.
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
     * Verifies that incremental chunks.
     *
     * @return void
     */
    public function testIncrementalChunks(): void
    {
        $streamParser = new StreamParser();

        // Feed data byte-by-byte to simulate TCP fragmentation
        $raw = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n";

        // Feed in two chunks that split in the middle
        $events1 = $streamParser->feed(substr($raw, 0, 20));
        $this->assertCount(0, $events1); // Not enough data yet

        $events2 = $streamParser->feed(substr($raw, 20));
        $this->assertCount(1, $events2);
        $this->assertSame('Hi', $events2[0]->text);
    }

    /**
     * Verifies that terminal event detection.
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
     * Verifies that empty chunk returns no events.
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
     * Test fixture for testParseToolUseEvent().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForParseToolUseEvent(): string
    {
        return "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n";
    }


    /**
     * Verifies that parse tool use event.
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
     * Test fixture for testParseToolResultEvent().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForParseToolResultEvent(): string
    {
        return "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"some results\"}\n\n";
    }


    /**
     * Verifies that parse tool result event.
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
     * Verifies that parse thinking event.
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
     * Verifies that tool use is not terminal.
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
     * Verifies that thinking is not terminal.
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
     * Verifies that tool result with JSON result.
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
     * Verifies that skips unknown event types.
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
     * Verifies that stream event from array throws on unknown type.
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
     * Verifies that stream event from array throws on missing type.
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
     * Verifies that skips event with missing type field.
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
     * Verifies that skips malformed JSON without corrupting buffer.
     *
     * @return void
     */
    public function testSkipsMalformedJsonWithoutCorruptingBuffer(): void
    {
        $streamParser = new StreamParser();

        // First chunk: malformed JSON followed by valid event
        $raw = "data: {malformed json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($raw);

        // Malformed event is skipped, valid event is returned
        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Verifies that buffer recovery after malformed JSON.
     *
     * @return void
     */
    public function testBufferRecoveryAfterMalformedJson(): void
    {
        $streamParser = new StreamParser();

        // Feed malformed JSON
        $events1 = $streamParser->feed("data: {broken\n\n");
        $this->assertCount(0, $events1);

        // Feed valid JSON - buffer should be clean
        $events2 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"recovered\"}\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('recovered', $events2[0]->text);
    }
    /**
     * Test fixture for testCompleteEventWithMultipleToolsUsed().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForCompleteEventWithMultipleToolsUsed(): string
    {
        return "data: {\"type\": \"complete\", \"text\": \"Result\", \"session_id\": \"s1\", \"usage\": {}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"name\": \"calc\", \"duration_ms\": 50}]}\n\n";
    }


    /**
     * Verifies that complete event with multiple tools used.
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
        $this->assertSame('calc', $events[0]->toolsUsed[1]['name']);
        $this->assertSame(50, $events[0]->toolsUsed[1]['duration_ms']);
    }
    /**
     * Test fixture for testMultipleDataLinesJoinedWithNewline().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForMultipleDataLinesJoinedWithNewline(): string
    {
        return "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";
    }


    /**
     * Verifies that multiple data lines joined with newline.
     *
     * @return void
     */
    public function testMultipleDataLinesJoinedWithNewline(): void
    {
        $streamParser = new StreamParser();
        // SSE spec: multiple data: lines in one event are joined with newlines
        $raw = $this->rawForMultipleDataLinesJoinedWithNewline();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Verifies that skipped events counter tracks parse errors.
     *
     * @return void
     */
    public function testSkippedEventsCounterTracksParseErrors(): void
    {
        $streamParser = new StreamParser();
        $this->assertSame(0, $streamParser->getSkippedEvents());

        // Two malformed events + one valid
        $raw = "data: {bad1\n\n"
            . "data: {bad2\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(2, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies that feed sets has_objective on the parsed event when true.
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
     * Verifies that has objective defaults false for non boolean values.
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
     * Verifies that citation event parsed.
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
     * Verifies that reasoning signature event parsed.
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
     * Verifies that reasoning redacted event parsed.
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
     * Verifies that buffer overflow throws stream interrupted exception.
     *
     * @return void
     */
    public function testBufferOverflowThrowsStreamInterruptedException(): void
    {
        $streamParser = new StreamParser();

        // Feed data that exceeds 10MB without a complete event (no double newline)
        $chunk = str_repeat('x', 1024 * 1024); // 1MB chunks

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('SSE buffer exceeded');

        for ($i = 0; $i < 11; $i++) {
            $streamParser->feed($chunk);
        }
    }

    /**
     * Verifies that buffer does not throw below limit.
     *
     * @return void
     */
    public function testBufferDoesNotThrowBelowLimit(): void
    {
        $streamParser = new StreamParser();

        // Feed 9MB of data without complete event — should not throw
        $chunk = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 9; $i++) {
            $streamParser->feed($chunk);
        }

        // No exception expected, parser still usable
        $this->assertSame(0, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies that try from array returns null on unknown type.
     *
     * @return void
     */
    /**
     * Verifies StreamEvent::tryFromArray() returns null for every documented
     * "unparseable type" shape (unknown enum, missing field, empty string).
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
     * Cases for testTryFromArrayReturnsNullForUnparseableEventShape().
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
     * Verifies that try from array returns event on known type.
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
     * Verifies that try from array returns complete event.
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
     * Verifies that skips unknown event in fixture stream.
     *
     * @return void
     */
    public function testSkipsUnknownEventInFixtureStream(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->loadFixture('sse-with-unknown-event.txt');

        $events = $streamParser->feed($raw);

        // Should parse text and complete, skipping the unknown "future_event"
        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies that complete event parses stop reason.
     *
     * @return void
     */
    public function testCompleteEventParsesStopReason(): void
    {
        $streamParser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-1\", \"usage\": {}, \"tools_used\": [], \"stop_reason\": \"end_turn\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertSame('end_turn', $events[0]->stopReason);
    }

    /**
     * Verifies that complete event parses context size fields.
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
     * Verifies that data with space vs without space parses differently.
     *
     * @return void
     */
    public function testDataWithSpaceVsWithoutSpaceParsesDifferently(): void
    {
        $streamParser = new StreamParser();

        // "data: X" should strip "data: " (6 chars) — content is just the JSON
        $raw1 = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";
        $events1 = $streamParser->feed($raw1);

        $this->assertCount(1, $events1);
        $this->assertSame('hello', $events1[0]->text);

        // "data:X" should strip "data:" (5 chars) — content starts at char 5
        $noSpaceStreamParser = new StreamParser();
        $raw2 = "data:{\"type\": \"text\", \"content\": \"world\"}\n\n";
        $events2 = $noSpaceStreamParser->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('world', $events2[0]->text);
    }

    /**
     * Verifies that comment line continues parsing remaining lines.
     *
     * @return void
     */
    public function testCommentLineContinuesParsingRemainingLines(): void
    {
        $streamParser = new StreamParser();

        // An event block with multiple lines: comment, data, comment, more data
        // The comment lines should be skipped (continue), not break parsing
        $raw = ": first comment\n"
            . "data: {\"type\": \"text\",\n"
            . ": middle comment\n"
            . "data:  \"content\": \"multi-line\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('multi-line', $events[0]->text);
    }

    /**
     * Verifies that empty data block returns no event.
     *
     * @return void
     */
    public function testEmptyDataBlockReturnsNoEvent(): void
    {
        $streamParser = new StreamParser();

        // An event block with only comment lines produces empty data
        $raw = ": just a heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"after\"}\n\n";

        $events = $streamParser->feed($raw);

        // The comment-only block should not produce an event, only the data block should
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    /**
     * Verifies that crlf normalization required.
     *
     * @return void
     */
    public function testCrlfNormalizationRequired(): void
    {
        $streamParser = new StreamParser();

        // CRLF line endings — both \r\n and bare \r should be normalized to \n
        $raw = "data: {\"type\": \"text\", \"content\": \"crlf\"}\r\n\r\n";
        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('crlf', $events[0]->text);

        // Bare CR
        $bareCrStreamParser = new StreamParser();
        $raw2 = "data: {\"type\": \"text\", \"content\": \"cr\"}\r\r";
        $events2 = $bareCrStreamParser->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('cr', $events2[0]->text);
    }

    /**
     * Verifies that buffer advancement after event parsed.
     *
     * @return void
     */
    public function testBufferAdvancementAfterEventParsed(): void
    {
        $streamParser = new StreamParser();

        // Feed two events — the buffer must advance past the first event's "\n\n"
        // correctly (by pos + 2) to parse the second event
        $raw = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame('A', $events[0]->text);
        $this->assertSame('B', $events[1]->text);
    }

    /**
     * Verifies that has objective defaults false when missing.
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
     * Verifies that stream event constructor defaults false for has objective.
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
     * Test fixture for testToolsUsedFiltersMalformedEntries().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForToolsUsedFiltersMalformedEntries(): string
    {
        return "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": null, \"usage\": {}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"no_name\": true}, \"not_array\", {\"name\": 123}]}\n\n";
    }


    /**
     * Verifies that tools used filters malformed entries.
     *
     * @return void
     */
    public function testToolsUsedFiltersMalformedEntries(): void
    {
        $streamParser = new StreamParser();
        $raw = $this->rawForToolsUsedFiltersMalformedEntries();

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        // Only the first tool entry has a valid string name
        $this->assertCount(1, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
    }

    /**
     * Verifies that multiple interrupts in complete event.
     *
     * @return void
     */
    public function testMultipleInterruptsInCompleteEvent(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "stop_reason": "interrupt", "interrupts": [{"tool_name": "deploy", "interrupt_id": "i1", "reason": "Approve"}, {"tool_name": "scale", "interrupt_id": "i2", "reason": "Confirm"}]}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertCount(2, $events[0]->interrupts);
        $this->assertSame('deploy', $events[0]->interrupts[0]['tool_name']);
        $this->assertSame('scale', $events[0]->interrupts[1]['tool_name']);
    }

    /**
     * Verifies that guardrail trace from nested trace key.
     *
     * @return void
     */
    public function testGuardrailTraceFromNestedTraceKey(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "trace": {"guardrail": {"action": "BLOCKED", "guardrail_id": "g1"}}}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]->guardrailTrace);
        $this->assertSame('BLOCKED', $events[0]->guardrailTrace['action']);
        $this->assertSame('g1', $events[0]->guardrailTrace['guardrail_id']);
    }

    /**
     * Verifies that guardrail trace top level takes precedence.
     *
     * @return void
     */
    public function testGuardrailTraceTopLevelTakesPrecedence(): void
    {
        $streamParser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "guardrail_trace": {"action": "TOP"}, "trace": {"guardrail": {"action": "NESTED"}}}' . "\n\n";

        $events = $streamParser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('TOP', $events[0]->guardrailTrace['action']);
    }

    /**
     * Verifies that guardrail trace null when trace key is not array.
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
     * Verifies that citation event parsed correctly.
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
     * Verifies that crlf split across chunks.
     *
     * @return void
     */
    public function testCrlfSplitAcrossChunks(): void
    {
        $streamParser = new StreamParser();

        // First chunk ends with \r, second starts with \n — the pair must
        // be normalised to a single \n, not produce \n\n (which would
        // create a spurious event boundary).
        $events1 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"split\"}\r");
        $this->assertCount(0, $events1, 'Trailing \\r should not close the event');

        $events2 = $streamParser->feed("\n\r\n");
        $this->assertCount(1, $events2, '\\r\\n split across chunks must normalise to one \\n');
        $this->assertSame('split', $events2[0]->text);
    }

    /**
     * Verifies that bare trailing cr normalised without following lf.
     *
     * @return void
     */
    public function testBareTrailingCrNormalisedWithoutFollowingLf(): void
    {
        $streamParser = new StreamParser();

        // Bare \r at end of chunk with no following \n — must normalise to \n
        $events1 = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"bare\"}\r");
        $this->assertCount(0, $events1);

        // Next chunk completes the event with another bare \r
        $events2 = $streamParser->feed("\r");
        $this->assertCount(1, $events2);
        $this->assertSame('bare', $events2[0]->text);
    }

    /**
     * Verifies that partial event at eof remains in buffer.
     *
     * @return void
     */
    public function testPartialEventAtEofRemainsInBuffer(): void
    {
        $streamParser = new StreamParser();

        // Feed a partial event without the double-newline terminator
        $events = $streamParser->feed('data: {"type": "text", "content": "partial"}');
        $this->assertCount(0, $events, 'Partial event without \\n\\n must not emit');

        // Completing the event should then emit it
        $events2 = $streamParser->feed("\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('partial', $events2[0]->text);
    }

    /**
     * Verifies that trailing newline after last event does not create phantom event.
     *
     * @return void
     */
    public function testTrailingNewlineAfterLastEventDoesNotCreatePhantomEvent(): void
    {
        $streamParser = new StreamParser();

        // Valid event followed by a single trailing \n (not enough for another event)
        $events = $streamParser->feed("data: {\"type\": \"text\", \"content\": \"ok\"}\n\n\n");
        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
    }

    /**
     * Verifies that consecutive empty event boundaries skipped.
     *
     * @return void
     */
    public function testConsecutiveEmptyEventBoundariesSkipped(): void
    {
        $streamParser = new StreamParser();

        // Multiple double-newlines in a row: empty data between them
        $events = $streamParser->feed("\n\ndata: {\"type\": \"text\", \"content\": \"after\"}\n\n");

        // The empty block produces null from parseEvent, should not appear
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    /**
     * Verifies that stream SSE eof mid event is discarded.
     *
     * @return void
     */
    public function testStreamSseEofMidEventIsDiscarded(): void
    {
        // Simulates an EOF mid-event in streamSse: the buffer holds an
        // incomplete event that never gets a \n\n terminator.
        // The extractSseData path in streamSse never sees it.
        $streamParser = new StreamParser();

        // Feed valid event + start of incomplete event
        $events = $streamParser->feed(
            "data: {\"type\": \"text\", \"content\": \"complete\"}\n\n"
            . 'data: {"type": "text", "content": "incom',
        );

        // Only the complete event should be returned
        $this->assertCount(1, $events);
        $this->assertSame('complete', $events[0]->text);

        // The parser's buffer still holds the incomplete data but no further
        // feed() calls come, so it's effectively discarded.
    }
}

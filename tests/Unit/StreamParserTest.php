<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

class StreamParserTest extends TestCase
{
    private function loadFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    public function testParseSimpleTextStream(): void
    {
        $parser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text.txt');

        $events = $parser->feed($raw);

        $this->assertCount(3, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello, ', $events[0]->text);
        $this->assertSame(StreamEventType::Text, $events[1]->type);
        $this->assertSame('world!', $events[1]->text);
        $this->assertSame(StreamEventType::Complete, $events[2]->type);
        $this->assertSame('Hello, world!', $events[2]->fullText);
        $this->assertSame('test-001', $events[2]->sessionId);
    }

    public function testParseCrlfDelimitedStream(): void
    {
        $parser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text-crlf.txt');

        $events = $parser->feed($raw);

        $this->assertCount(3, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello, ', $events[0]->text);
        $this->assertSame(StreamEventType::Text, $events[1]->type);
        $this->assertSame('world!', $events[1]->text);
        $this->assertSame(StreamEventType::Complete, $events[2]->type);
        $this->assertSame('Hello, world!', $events[2]->fullText);
        $this->assertSame('test-crlf', $events[2]->sessionId);
    }

    public function testSkipsHeartbeatComments(): void
    {
        $parser = new StreamParser();
        $raw = $this->loadFixture('sse-with-heartbeat.txt');

        $events = $parser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Processing...', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
    }

    public function testErrorMidStream(): void
    {
        $parser = new StreamParser();
        $raw = $this->loadFixture('sse-error-mid-stream.txt');

        $events = $parser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
        $this->assertSame('INTERNAL', $events[1]->errorCode);
        $this->assertSame('Model rate limited', $events[1]->errorMessage);
    }

    public function testIncrementalChunks(): void
    {
        $parser = new StreamParser();

        // Feed data byte-by-byte to simulate TCP fragmentation
        $raw = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n";

        // Feed in two chunks that split in the middle
        $events1 = $parser->feed(substr($raw, 0, 20));
        $this->assertCount(0, $events1); // Not enough data yet

        $events2 = $parser->feed(substr($raw, 20));
        $this->assertCount(1, $events2);
        $this->assertSame('Hi', $events2[0]->text);
    }

    public function testTerminalEventDetection(): void
    {
        $parser = new StreamParser();
        $raw = $this->loadFixture('sse-simple-text.txt');

        $events = $parser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
        $this->assertFalse($events[1]->isTerminal());
        $this->assertTrue($events[2]->isTerminal());
    }

    public function testEmptyChunkReturnsNoEvents(): void
    {
        $parser = new StreamParser();

        $events = $parser->feed('');

        $this->assertSame([], $events);
    }

    public function testParseToolUseEvent(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolUse, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame(['query' => 'test'], $events[0]->toolInput);
    }

    public function testParseToolResultEvent(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"some results\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame('some results', $events[0]->toolResult);
    }

    public function testParseThinkingEvent(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"thinking\", \"content\": \"Let me reason about this...\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Thinking, $events[0]->type);
        $this->assertSame('Let me reason about this...', $events[0]->text);
    }

    public function testToolUseIsNotTerminal(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"tool_use\", \"tool_name\": \"search\", \"tool_input\": {}}\n\n";

        $events = $parser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
    }

    public function testThinkingIsNotTerminal(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"thinking\", \"content\": \"hmm\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertFalse($events[0]->isTerminal());
    }

    public function testToolResultWithJsonResult(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"tool_result\", \"tool_name\": \"api\", \"result\": {\"count\": 42}}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('{"count":42}', $events[0]->toolResult);
    }

    public function testSkipsUnknownEventTypes(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"internal_debug\", \"content\": \"something\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(1, $parser->getSkippedEvents());
    }

    public function testStreamEventFromArrayThrowsOnUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stream event type: "unknown_type"');

        \StrandsPhpClient\Streaming\StreamEvent::fromArray(['type' => 'unknown_type']);
    }

    public function testStreamEventFromArrayThrowsOnMissingType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stream event type: "(missing)"');

        \StrandsPhpClient\Streaming\StreamEvent::fromArray(['type' => '']);
    }

    public function testSkipsEventWithMissingTypeField(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"content\": \"no type field\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
        $this->assertSame(1, $parser->getSkippedEvents());
    }

    public function testSkipsMalformedJsonWithoutCorruptingBuffer(): void
    {
        $parser = new StreamParser();

        // First chunk: malformed JSON followed by valid event
        $raw = "data: {malformed json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $parser->feed($raw);

        // Malformed event is skipped, valid event is returned
        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]->text);
    }

    public function testBufferRecoveryAfterMalformedJson(): void
    {
        $parser = new StreamParser();

        // Feed malformed JSON
        $events1 = $parser->feed("data: {broken\n\n");
        $this->assertCount(0, $events1);

        // Feed valid JSON - buffer should be clean
        $events2 = $parser->feed("data: {\"type\": \"text\", \"content\": \"recovered\"}\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('recovered', $events2[0]->text);
    }

    public function testCompleteEventWithMultipleToolsUsed(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Result\", \"session_id\": \"s1\", \"usage\": {}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"name\": \"calc\", \"duration_ms\": 50}]}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertCount(2, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
        $this->assertSame(100, $events[0]->toolsUsed[0]['duration_ms']);
        $this->assertSame('calc', $events[0]->toolsUsed[1]['name']);
        $this->assertSame(50, $events[0]->toolsUsed[1]['duration_ms']);
    }

    public function testMultipleDataLinesJoinedWithNewline(): void
    {
        $parser = new StreamParser();
        // SSE spec: multiple data: lines in one event are joined with newlines
        $raw = "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
    }

    public function testSkippedEventsCounterTracksParseErrors(): void
    {
        $parser = new StreamParser();
        $this->assertSame(0, $parser->getSkippedEvents());

        // Two malformed events + one valid
        $raw = "data: {bad1\n\n"
            . "data: {bad2\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(2, $parser->getSkippedEvents());
    }

    public function testParsesHasObjectiveFlagWhenTrue(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": true}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->hasObjective);
    }

    public function testHasObjectiveDefaultsFalseForNonBooleanValues(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\", \"has_objective\": \"true\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->hasObjective);
    }

    public function testCitationEventParsed(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"citation\", \"citation\": {\"source\": \"doc.pdf\", \"page\": 3, \"text\": \"relevant excerpt\"}}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertSame(['source' => 'doc.pdf', 'page' => 3, 'text' => 'relevant excerpt'], $events[0]->citation);
    }

    public function testReasoningSignatureEventParsed(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"reasoning_signature\", \"signature\": \"abc123def456\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningSignature, $events[0]->type);
        $this->assertSame('abc123def456', $events[0]->reasoningSignature);
    }

    public function testReasoningRedactedEventParsed(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"reasoning_redacted\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ReasoningRedacted, $events[0]->type);
    }

    public function testBufferOverflowThrowsStreamInterruptedException(): void
    {
        $parser = new StreamParser();

        // Feed data that exceeds 10MB without a complete event (no double newline)
        $chunk = str_repeat('x', 1024 * 1024); // 1MB chunks

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('SSE buffer exceeded');

        for ($i = 0; $i < 11; $i++) {
            $parser->feed($chunk);
        }
    }

    public function testBufferDoesNotThrowBelowLimit(): void
    {
        $parser = new StreamParser();

        // Feed 9MB of data without complete event — should not throw
        $chunk = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 9; $i++) {
            $parser->feed($chunk);
        }

        // No exception expected, parser still usable
        $this->assertSame(0, $parser->getSkippedEvents());
    }

    public function testTryFromArrayReturnsNullOnUnknownType(): void
    {
        $result = \StrandsPhpClient\Streaming\StreamEvent::tryFromArray([
            'type' => 'future_event',
            'data' => 'something new',
        ]);

        $this->assertNull($result);
    }

    public function testTryFromArrayReturnsNullOnMissingType(): void
    {
        $result = \StrandsPhpClient\Streaming\StreamEvent::tryFromArray([
            'content' => 'no type',
        ]);

        $this->assertNull($result);
    }

    public function testTryFromArrayReturnsNullOnEmptyType(): void
    {
        $result = \StrandsPhpClient\Streaming\StreamEvent::tryFromArray([
            'type' => '',
        ]);

        $this->assertNull($result);
    }

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

    public function testSkipsUnknownEventInFixtureStream(): void
    {
        $parser = new StreamParser();
        $raw = file_get_contents(__DIR__ . '/../Fixtures/sse-with-unknown-event.txt');

        $events = $parser->feed($raw);

        // Should parse text and complete, skipping the unknown "future_event"
        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
        $this->assertSame(1, $parser->getSkippedEvents());
    }

    public function testCompleteEventParsesStopReason(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-1\", \"usage\": {}, \"tools_used\": [], \"stop_reason\": \"end_turn\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertSame('end_turn', $events[0]->stopReason);
    }

    public function testDataWithSpaceVsWithoutSpaceParsesDifferently(): void
    {
        $parser = new StreamParser();

        // "data: X" should strip "data: " (6 chars) — content is just the JSON
        $raw1 = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";
        $events1 = $parser->feed($raw1);

        $this->assertCount(1, $events1);
        $this->assertSame('hello', $events1[0]->text);

        // "data:X" should strip "data:" (5 chars) — content starts at char 5
        $parser2 = new StreamParser();
        $raw2 = "data:{\"type\": \"text\", \"content\": \"world\"}\n\n";
        $events2 = $parser2->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('world', $events2[0]->text);
    }

    public function testCommentLineContinuesParsingRemainingLines(): void
    {
        $parser = new StreamParser();

        // An event block with multiple lines: comment, data, comment, more data
        // The comment lines should be skipped (continue), not break parsing
        $raw = ": first comment\n"
            . "data: {\"type\": \"text\",\n"
            . ": middle comment\n"
            . "data:  \"content\": \"multi-line\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('multi-line', $events[0]->text);
    }

    public function testEmptyDataBlockReturnsNoEvent(): void
    {
        $parser = new StreamParser();

        // An event block with only comment lines produces empty data
        $raw = ": just a heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"after\"}\n\n";

        $events = $parser->feed($raw);

        // The comment-only block should not produce an event, only the data block should
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    public function testCrlfNormalizationRequired(): void
    {
        $parser = new StreamParser();

        // CRLF line endings — both \r\n and bare \r should be normalized to \n
        $raw = "data: {\"type\": \"text\", \"content\": \"crlf\"}\r\n\r\n";
        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('crlf', $events[0]->text);

        // Bare CR
        $parser2 = new StreamParser();
        $raw2 = "data: {\"type\": \"text\", \"content\": \"cr\"}\r\r";
        $events2 = $parser2->feed($raw2);

        $this->assertCount(1, $events2);
        $this->assertSame('cr', $events2[0]->text);
    }

    public function testBufferAdvancementAfterEventParsed(): void
    {
        $parser = new StreamParser();

        // Feed two events — the buffer must advance past the first event's "\n\n"
        // correctly (by pos + 2) to parse the second event
        $raw = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(2, $events);
        $this->assertSame('A', $events[0]->text);
        $this->assertSame('B', $events[1]->text);
    }

    public function testHasObjectiveDefaultsFalseWhenMissing(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertFalse($events[0]->hasObjective);
    }

    public function testStreamEventConstructorDefaultsFalseForHasObjective(): void
    {
        $event = new \StrandsPhpClient\Streaming\StreamEvent(
            type: StreamEventType::Text,
            text: 'hello',
        );

        $this->assertFalse($event->hasObjective);
    }

    public function testToolsUsedFiltersMalformedEntries(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": null, \"usage\": {}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"no_name\": true}, \"not_array\", {\"name\": 123}]}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        // Only the first tool entry has a valid string name
        $this->assertCount(1, $events[0]->toolsUsed);
        $this->assertSame('search', $events[0]->toolsUsed[0]['name']);
    }

    public function testMultipleInterruptsInCompleteEvent(): void
    {
        $parser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "stop_reason": "interrupt", "interrupts": [{"tool_name": "deploy", "interrupt_id": "i1", "reason": "Approve"}, {"tool_name": "scale", "interrupt_id": "i2", "reason": "Confirm"}]}' . "\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertCount(2, $events[0]->interrupts);
        $this->assertSame('deploy', $events[0]->interrupts[0]['tool_name']);
        $this->assertSame('scale', $events[0]->interrupts[1]['tool_name']);
    }

    public function testGuardrailTraceFromNestedTraceKey(): void
    {
        $parser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "trace": {"guardrail": {"action": "BLOCKED", "guardrail_id": "g1"}}}' . "\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]->guardrailTrace);
        $this->assertSame('BLOCKED', $events[0]->guardrailTrace['action']);
        $this->assertSame('g1', $events[0]->guardrailTrace['guardrail_id']);
    }

    public function testGuardrailTraceTopLevelTakesPrecedence(): void
    {
        $parser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "guardrail_trace": {"action": "TOP"}, "trace": {"guardrail": {"action": "NESTED"}}}' . "\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame('TOP', $events[0]->guardrailTrace['action']);
    }

    public function testGuardrailTraceNullWhenTraceKeyIsNotArray(): void
    {
        $parser = new StreamParser();
        $raw = 'data: {"type": "complete", "text": "", "session_id": null, "usage": {}, "tools_used": [], "trace": "not_array"}' . "\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->guardrailTrace);
    }

    public function testCitationEventParsedCorrectly(): void
    {
        $parser = new StreamParser();
        $raw = 'data: {"type": "citation", "citation": {"source": "doc1", "text": "relevant passage"}}' . "\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Citation, $events[0]->type);
        $this->assertNotNull($events[0]->citation);
        $this->assertSame('doc1', $events[0]->citation['source']);
    }

    public function testCrlfSplitAcrossChunks(): void
    {
        $parser = new StreamParser();

        // First chunk ends with \r, second starts with \n — the pair must
        // be normalised to a single \n, not produce \n\n (which would
        // create a spurious event boundary).
        $events1 = $parser->feed("data: {\"type\": \"text\", \"content\": \"split\"}\r");
        $this->assertCount(0, $events1, 'Trailing \\r should not close the event');

        $events2 = $parser->feed("\n\r\n");
        $this->assertCount(1, $events2, '\\r\\n split across chunks must normalise to one \\n');
        $this->assertSame('split', $events2[0]->text);
    }

    public function testBareTrailingCrNormalisedWithoutFollowingLf(): void
    {
        $parser = new StreamParser();

        // Bare \r at end of chunk with no following \n — must normalise to \n
        $events1 = $parser->feed("data: {\"type\": \"text\", \"content\": \"bare\"}\r");
        $this->assertCount(0, $events1);

        // Next chunk completes the event with another bare \r
        $events2 = $parser->feed("\r");
        $this->assertCount(1, $events2);
        $this->assertSame('bare', $events2[0]->text);
    }

    public function testPartialEventAtEofRemainsInBuffer(): void
    {
        $parser = new StreamParser();

        // Feed a partial event without the double-newline terminator
        $events = $parser->feed('data: {"type": "text", "content": "partial"}');
        $this->assertCount(0, $events, 'Partial event without \\n\\n must not emit');

        // Completing the event should then emit it
        $events2 = $parser->feed("\n\n");
        $this->assertCount(1, $events2);
        $this->assertSame('partial', $events2[0]->text);
    }

    public function testTrailingNewlineAfterLastEventDoesNotCreatePhantomEvent(): void
    {
        $parser = new StreamParser();

        // Valid event followed by a single trailing \n (not enough for another event)
        $events = $parser->feed("data: {\"type\": \"text\", \"content\": \"ok\"}\n\n\n");
        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
    }

    public function testConsecutiveEmptyEventBoundariesSkipped(): void
    {
        $parser = new StreamParser();

        // Multiple double-newlines in a row: empty data between them
        $events = $parser->feed("\n\ndata: {\"type\": \"text\", \"content\": \"after\"}\n\n");

        // The empty block produces null from parseEvent, should not appear
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    public function testStreamSseEofMidEventIsDiscarded(): void
    {
        // Simulates an EOF mid-event in streamSse: the buffer holds an
        // incomplete event that never gets a \n\n terminator.
        // The extractSseData path in streamSse never sees it.
        $parser = new StreamParser();

        // Feed valid event + start of incomplete event
        $events = $parser->feed(
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

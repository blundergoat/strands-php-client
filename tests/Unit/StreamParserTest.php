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

    public function testCompleteEventParsesStopReason(): void
    {
        $parser = new StreamParser();
        $raw = "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-1\", \"usage\": {}, \"tools_used\": [], \"stop_reason\": \"end_turn\"}\n\n";

        $events = $parser->feed($raw);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Complete, $events[0]->type);
        $this->assertSame('end_turn', $events[0]->stopReason);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies individual SSE event types hydrate safely and unknown events remain forward compatible.
 *
 * Use these tests when changing StreamEvent parsing or adding supported wire event types.
 * They protect live callbacks from malformed or newer server frames.
 */
class StreamParserEventHydrationTest extends TestCase
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
     * Builds a tool-use frame matching an update an application can receive.
     * Use it to verify the callback receives the tool name and input before execution.
     *
     * @return string Complete SSE frame; never empty.
     */
    private function toolUseSseFrame(): string
    {
        return "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n";
    }

    /**
     * Verifies the parser hydrates tool-use events, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testParseToolUseEvent(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->toolUseSseFrame();

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolUse, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame(['query' => 'test'], $events[0]->toolInput);
    }

    /**
     * Builds a tool-result frame matching an update an application can receive.
     * Use it to verify the callback receives the tool name and returned content.
     *
     * @return string Complete SSE frame; never empty.
     */
    private function toolResultSseFrame(): string
    {
        return "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"some results\"}\n\n";
    }

    /**
     * Verifies the parser hydrates tool-result events, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testParseToolResultEvent(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->toolResultSseFrame();

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame('some results', $events[0]->toolResult);
    }

    /**
     * Verifies the parser hydrates thinking events, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testParseThinkingEvent(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"thinking\", \"content\": \"Let me reason about this...\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Thinking, $events[0]->type);
        $this->assertSame('Let me reason about this...', $events[0]->text);
    }

    /**
     * Verifies tool-use events are not terminal, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testToolUseIsNotTerminal(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"tool_use\", \"tool_name\": \"search\", \"tool_input\": {}}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertFalse($events[0]->isTerminal());
    }

    /**
     * Verifies thinking events are not terminal, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testThinkingIsNotTerminal(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"thinking\", \"content\": \"hmm\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertFalse($events[0]->isTerminal());
    }

    /**
     * Verifies tool-result events preserve JSON results, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testToolResultWithJsonResult(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"tool_result\", \"tool_name\": \"api\", \"result\": {\"count\": 42}}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::ToolResult, $events[0]->type);
        $this->assertSame('{"count":42}', $events[0]->toolResult);
    }

    /**
     * Verifies the parser skips unknown event types, keeping malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testSkipsUnknownEventTypes(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"type\": \"internal_debug\", \"content\": \"something\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies StreamEvent::fromArray() throws on unknown type.
     * This keeps malformed or future frames from breaking live callbacks.
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
     * Verifies StreamEvent::fromArray() throws on missing type.
     * This keeps malformed or future frames from breaking live callbacks.
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
     * Verifies the parser skips events with no type field.
     * This keeps malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testSkipsEventWithMissingTypeField(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = "data: {\"content\": \"no type field\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->text);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies StreamEvent::tryFromArray() returns null for unparseable event shape.
     * This keeps malformed or future frames from breaking live callbacks.
     *
     * @param array<string, mixed> $payload Non-empty wire payload that cannot resolve to a known event type.
     * @return void
     */
    #[DataProvider('unparseableEventPayloadProvider')]
    public function testTryFromArrayReturnsNullForUnparseableEventShape(array $payload): void
    {
        $this->assertNull(\StrandsPhpClient\Streaming\StreamEvent::tryFromArray($payload));
    }

    /**
     * Lists malformed event payloads the parser must skip without breaking live updates.
     * An empty provider would leave forward-compatible stream handling unverified.
     *
     * @return iterable<string, array{0: array<string, mixed>}> Non-empty malformed payload cases for live app updates.
     */
    public static function unparseableEventPayloadProvider(): iterable
    {
        yield 'unknown type enum' => [['type' => 'future_event', 'data' => 'something new']];
        yield 'type field missing entirely' => [['content' => 'no type']];
        yield 'type field present but empty string' => [['type' => '']];
    }

    /**
     * Verifies StreamEvent::tryFromArray() returns typed events for known types.
     * This keeps malformed or future frames from breaking live callbacks.
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
     * Verifies the parser skips unknown fixture events.
     * This keeps malformed or future frames from breaking live callbacks.
     *
     * @return void
     */
    public function testSkipsUnknownEventInFixtureStream(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-with-unknown-event.txt');

        $events = $streamParser->feed($sseFrame);

        // A future event is hidden while the recognized text and completion still reach an older app.
        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
        $this->assertSame(1, $streamParser->getSkippedEvents());
    }


}

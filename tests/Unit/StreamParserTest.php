<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

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
     * @param string $fixtureName Non-empty SSE fixture filename under tests/Fixtures/.
     * @return string Captured SSE bytes; empty means the parser receives no event.
     */
    private function loadFixture(string $fixtureName): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $fixtureName);
    }

    /**
     * Verifies the parser handles a simple text stream so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testParseSimpleTextStream(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-simple-text.txt');

        $events = $streamParser->feed($sseFrame);

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
     * Verifies the parser handles a CRLF-delimited stream.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
     *
     * @return void
     */
    public function testParseCrlfDelimitedStream(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-simple-text-crlf.txt');

        $events = $streamParser->feed($sseFrame);

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
     * Verifies the parser handles CRLF split across chunks.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies the parser skips heartbeat comments so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testSkipsHeartbeatComments(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-with-heartbeat.txt');

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Processing...', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[1]->type);
    }

    /**
     * Verifies the parser surfaces errors that arrive mid-stream so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testErrorMidStream(): void
    {
        $streamParser = new StreamParser();
        $sseFrame = $this->loadFixture('sse-error-mid-stream.txt');

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
        $this->assertSame('INTERNAL', $events[1]->errorCode);
        $this->assertSame('Model rate limited', $events[1]->errorMessage);
    }

    /**
     * Verifies the parser joins incremental network chunks so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testIncrementalChunks(): void
    {
        $streamParser = new StreamParser();

        // This frame is split mid-payload to reproduce a proxy delivering the user's live update across callbacks.
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n";

        // The first partial callback must stay hidden because the user cannot render an incomplete event.
        $events1 = $streamParser->feed(substr($sseFrame, 0, 20));
        $this->assertCount(0, $events1);

        $events2 = $streamParser->feed(substr($sseFrame, 20));
        $this->assertCount(1, $events2);
        $this->assertSame('Hi', $events2[0]->text);
    }

    /**
     * Verifies an empty chunk produces no events so network chunks cannot corrupt callback order.
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
     * Verifies the parser skips malformed JSON without corrupting its buffer.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
     *
     * @return void
     */
    public function testSkipsMalformedJsonWithoutCorruptingBuffer(): void
    {
        $streamParser = new StreamParser();

        // A damaged frame followed by a valid update reproduces a stream that recovers without losing later user content.
        $sseFrame = "data: {malformed json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        // The user sees the valid update and never receives the malformed frame.
        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Verifies the parser recovers after malformed JSON so network chunks cannot corrupt callback order.
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
     * Builds one JSON event split across two SSE data lines.
     * Use it to verify the app receives one joined update instead of two broken frames.
     *
     * @return string Complete multi-line SSE frame; never empty.
     */
    private function multilineDataSseFrame(): string
    {
        return "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";
    }

    /**
     * Verifies the parser joins multiple data lines with a newline.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
     *
     * @return void
     */
    public function testMultipleDataLinesJoinedWithNewline(): void
    {
        $streamParser = new StreamParser();
        // A wrapper may split one JSON object across data lines; the UI still receives one decoded event.
        $sseFrame = $this->multilineDataSseFrame();

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
    }

    /**
     * Verifies the skipped-event counter tracks parse failures.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
     *
     * @return void
     */
    public function testSkippedEventsCounterTracksParseErrors(): void
    {
        $streamParser = new StreamParser();
        $this->assertSame(0, $streamParser->getSkippedEvents());

        // Two broken frames followed by a valid update let the app report accurate compatibility diagnostics without disrupting the user.
        $sseFrame = "data: {bad1\n\n"
            . "data: {bad2\n\n"
            . "data: {\"type\": \"text\", \"content\": \"ok\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame(2, $streamParser->getSkippedEvents());
    }

    /**
     * Verifies an oversized incomplete frame raises StreamInterruptedException.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies a bounded incomplete frame remains buffered so network chunks cannot corrupt callback order.
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
     * Verifies many bounded frames in one chunk stay below the incomplete-frame limit.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies SSE data prefixes preserve the optional single space.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies an SSE comment does not hide later data lines.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
     *
     * @return void
     */
    public function testCommentLineContinuesParsingRemainingLines(): void
    {
        $streamParser = new StreamParser();

        // Heartbeat comments between data lines are ignored, allowing the user's one logical event to continue across them.
        $sseFrame = ": first comment\n"
            . "data: {\"type\": \"text\",\n"
            . ": middle comment\n"
            . "data:  \"content\": \"multi-line\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(1, $events);
        $this->assertSame('multi-line', $events[0]->text);
    }

    /**
     * Verifies an empty SSE data block produces no event so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testEmptyDataBlockReturnsNoEvent(): void
    {
        $streamParser = new StreamParser();

        // A heartbeat-only frame carries no user-visible content, while the next data frame does.
        $sseFrame = ": just a heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"after\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        // Only the real data frame reaches the app callback.
        $this->assertCount(1, $events);
        $this->assertSame('after', $events[0]->text);
    }

    /**
     * Verifies CRLF input is normalized before event parsing so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testCrlfNormalizationRequired(): void
    {
        $streamParser = new StreamParser();

        // A Windows-style CRLF delimiter must finish the same user event as LF.
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"crlf\"}\r\n\r\n";
        $events = $streamParser->feed($sseFrame);

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
     * Verifies the parser advances beyond each completed frame so network chunks cannot corrupt callback order.
     *
     * @return void
     */
    public function testBufferAdvancementAfterEventParsed(): void
    {
        $streamParser = new StreamParser();

        // Advancing past the first delimiter ensures both consecutive updates reach the user's live screen in order.
        $sseFrame = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n";

        $events = $streamParser->feed($sseFrame);

        $this->assertCount(2, $events);
        $this->assertSame('A', $events[0]->text);
        $this->assertSame('B', $events[1]->text);
    }

    /**
     * Verifies CRLF split across chunks so network chunks cannot corrupt callback order.
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
     * Verifies a trailing carriage return is normalized without a following line feed.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies partial event at EOF remains in buffer so network chunks cannot corrupt callback order.
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
     * Verifies a trailing newline does not create a phantom event.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies consecutive empty event boundaries produce no callbacks.
     * This prevents network chunk boundaries from corrupting the event sequence delivered to callbacks.
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
     * Verifies raw SSE parsing discards an incomplete event at EOF so network chunks cannot corrupt callback order.
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

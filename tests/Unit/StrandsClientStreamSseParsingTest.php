<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies custom SSE frames become the exact events an application callback expects.
 *
 * Use these tests when changing CRLF handling, multi-line data, comments, or event names.
 * They protect custom live screens from lost, split, or phantom updates.
 */
class StrandsClientStreamSseParsingTest extends TestCase
{
    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the raw-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseData SSE bytes yielded by the mock; empty means the app receives no events.
     * @return HttpTransport Mock transport that delivers the supplied SSE data; never null.
     */
    private function createStreamingTransport(string $sseData): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        return $mock;
    }

    /**
     * Verifies streamSse() parses events, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseParsesEvents(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" world\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello world\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(3, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('Hello', $events[0]['content']);
        $this->assertSame('text', $events[1]['type']);
        $this->assertSame(' world', $events[1]['content']);
        $this->assertSame('complete', $events[2]['type']);
        $this->assertSame('Hello world', $events[2]['text']);
    }

    /**
     * Verifies streamSse() preserves unknown fields, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSsePreservesUnknownFields(): void
    {
        $sseData = 'data: {"type": "complete", "text": "done", '
            . '"verification": {"score": 95}, "model": "claude-3", '
            . '"metadata": {"custom": true}}' . "\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('done', $events[0]['text']);
        $this->assertSame(['score' => 95], $events[0]['verification']);
        $this->assertSame('claude-3', $events[0]['model']);
        $this->assertSame(['custom' => true], $events[0]['metadata']);
    }

    /**
     * Verifies streamSse() skips malformed json, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseSkipsMalformedJson(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {not valid json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"third\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('third', $events[1]['content']);
    }

    /**
     * Verifies streamSse() handles multiline data, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseHandlesMultiLineData(): void
    {
        $sseData = "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies streamSse() skips heartbeat comments, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseSkipsHeartbeatComments(): void
    {
        $sseData = ": heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n"
            . ": another heartbeat\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies streamSse() handles CRLF line endings, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseHandlesCrlfLineEndings(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"hello\"}\r\n\r\n"
            . "data: {\"type\": \"complete\", \"text\": \"hello\"}\r\n\r\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('hello', $events[0]['content']);
        $this->assertSame('complete', $events[1]['type']);
    }

    /**
     * Verifies streamSse() handles CRLF split across chunks, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseHandlesCrlfSplitAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
                $onChunk->__invoke("data: {\"type\": \"text\",\r");
                $onChunk->__invoke("\ndata: \"content\": \"hello\"}\r\n\r\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies streamSse() rejects an unbounded incomplete frame, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseRejectsAnUnboundedIncompleteFrame(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
                $chunk = str_repeat('x', 1024 * 1024);
                // Repeated unfinished chunks reproduce a wrapper that exceeds the user's streaming safety limit.
                for ($chunkIndex = 0; $chunkIndex < 11; $chunkIndex++) {
                    $onChunk->__invoke($chunk);
                }
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('SSE buffer exceeded');

        $strandsClient->streamSse('/test-stream', ['data' => 'test'], static function (): void {
        });
    }

    /**
     * Verifies streamSse() handles chunked delivery, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseHandlesChunkedDelivery(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
                // Splitting one frame across callbacks reproduces normal TCP fragmentation while the user waits.
                $onChunk->__invoke('data: {"type":');
                $onChunk->__invoke(" \"text\", \"content\": \"hello\"}\n\n");
                // A later complete frame proves buffering the first partial frame did not delay subsequent UI updates.
                $onChunk->__invoke("data: {\"type\": \"complete\", \"text\": \"hello\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
        $this->assertSame('complete', $events[1]['type']);
    }

    /**
     * Verifies streamSse() data without space parses correctly, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseDataWithoutSpaceParsesCorrectly(): void
    {
        // A valid data: prefix without the optional space must deliver the same callback object to the app.
        $sseData = "data:{\"type\": \"text\", \"content\": \"hello\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies streamSse() CRLF in middle of event block, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseCrlfInMiddleOfEventBlock(): void
    {
        // CRLF normalization keeps the two data lines in one UI event; without it, the apparent blank line would split the payload early.
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
                // Two CRLF data lines form one logical event the custom screen should receive once.
                $onChunk->__invoke("data: {\"type\": \"text\",\r\ndata:  \"content\": \"hello\"}\r\n\r\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        // Normalized framing delivers exactly one callback instead of splitting the user's payload early.
        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies streamSse() comment lines between data lines, keeping raw SSE callbacks predictable for calling applications.
     *
     * @return void
     */
    public function testStreamSseCommentLinesBetweenDataLines(): void
    {
        // Wrapper heartbeat comments between data lines must not break the user's one logical event.
        $sseData = ": comment 1\n"
            . "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . ": comment 2\n"
            . ": comment 3\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('second', $events[1]['content']);
    }
}

<?php

declare(strict_types=1);

/**
 * Tests caller-visible Strands Client Stream Sse behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies Strands Client Stream Sse behavior that application users rely on.
 */
class StrandsClientStreamSseTest extends TestCase
{
    /**
     * Create streaming transport for the test scenario.
     *
     * @param string $sseData SSE fixture data yielded by the mock transport.
     * @return HttpTransport Value produced by the method.
     */
    private function createStreamingTransport(string $sseData): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        return $mock;
    }

    /**
     * Verifies that stream SSE sends correct URL.
     *
     * @return void
     */
    public function testStreamSseSendsCorrectUrl(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                'http://localhost:8081/file-summarise-stream',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke("data: {\"type\": \"complete\", \"text\": \"done\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/file-summarise-stream', ['file_base64' => 'abc'], function () use (&$eventCount): void {
            $eventCount++;
        });

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE sends correct payload.
     *
     * @return void
     */
    public function testStreamSseSendsCorrectPayload(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->callback(fn (array $headers) => $headers['Content-Type'] === 'application/json'
                    && $headers['Accept'] === 'text/event-stream'),
                $this->callback(function (string $body) {
                    $data = json_decode($body, true);

                    return $data['file_base64'] === 'abc'
                        && $data['template'] === 'default';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke("data: {\"type\": \"complete\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/file-summarise-stream', [
            'file_base64' => 'abc',
            'template' => 'default',
        ], function () use (&$eventCount): void {
            $eventCount++;
        });

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE applies auth.
     *
     * @return void
     */
    public function testStreamSseAppliesAuth(): void
    {
        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->once())
            ->method('authenticate')
            ->with(
                $this->anything(),
                'POST',
                'http://localhost:8081/file-summarise-stream',
                $this->anything(),
            )
            ->willReturnArgument(0);

        $sseData = "data: {\"type\": \"complete\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/file-summarise-stream', ['file_base64' => 'abc'], function () use (&$eventCount): void {
            $eventCount++;
        });

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE parses events.
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
     * Verifies that stream SSE preserves unknown fields.
     *
     * @return void
     */
    public function testStreamSsePreservesUnknownFields(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"done\", \"verification\": {\"score\": 95}, \"model\": \"claude-3\", \"metadata\": {\"custom\": true}}\n\n";

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
     * Verifies that stream SSE skips malformed JSON.
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
     * Verifies that stream SSE handles multi line data.
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
     * Verifies that stream SSE skips heartbeat comments.
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
     * Verifies that stream SSE uses config timeout by default.
     *
     * @return void
     */
    public function testStreamSseUsesConfigTimeoutByDefault(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                120,
                10,
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke("data: {\"type\": \"complete\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () use (&$eventCount): void {
            $eventCount++;
        });

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE uses per request timeout.
     *
     * @return void
     */
    public function testStreamSseUsesPerRequestTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                15,
                10,
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke("data: {\"type\": \"complete\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () use (&$eventCount): void {
            $eventCount++;
        }, timeout: 15);

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE cancels on false return.
     *
     * @return void
     */
    public function testStreamSseCancelsOnFalseReturn(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"third\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): bool {
            $events[] = $event;

            // Stop after the second raw event to prove callback cancellation works.
            return count($events) < 2;
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('second', $events[1]['content']);
    }

    /**
     * Verifies that stream SSE void callback continues.
     *
     * @return void
     */
    public function testStreamSseVoidCallbackContinues(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n"
            . "data: {\"type\": \"complete\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(3, $events);
    }

    /**
     * Verifies that stream SSE cancels across chunks.
     *
     * @return void
     */
    public function testStreamSseCancelsAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // First chunk delivers one event
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // Second chunk delivers another — but callback already cancelled
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"second\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): bool {
            $events[] = $event;

            // Stop immediately so the app receives only the first raw event.
            return false;
        });

        $this->assertCount(1, $events);
        $this->assertSame('first', $events[0]['content']);
    }

    /**
     * Verifies that stream SSE handles crlf line endings.
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
     * Verifies that stream SSE handles chunked delivery.
     *
     * @return void
     */
    public function testStreamSseHandlesChunkedDelivery(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // SSE event split across two TCP chunks
                $onChunk->__invoke('data: {"type":');
                $onChunk->__invoke(" \"text\", \"content\": \"hello\"}\n\n");
                // Second complete event in one chunk
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
     * Verifies that stream SSE propagates transport error.
     *
     * @return void
     */
    public function testStreamSsePropagatesTransportError(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willThrowException(new AgentErrorException('Internal Server Error', statusCode: 500));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () {
        });
    }

    /**
     * Verifies that stream SSE throws on encoding failure.
     *
     * @return void
     */
    public function testStreamSseThrowsOnEncodingFailure(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        try {
            $strandsClient->streamSse('/test-stream', ['bad_value' => NAN], function () {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertStringContainsString('Failed to encode request payload', $e->getMessage());
            $this->assertStringContainsString('Inf and NaN', $e->getMessage());
        }
    }

    /**
     * Verifies that stream SSE rejects zero timeout.
     *
     * @return void
     */
    public function testStreamSseRejectsZeroTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () {
        }, timeout: 0);
    }

    /**
     * Verifies that stream SSE rejects negative timeout.
     *
     * @return void
     */
    public function testStreamSseRejectsNegativeTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () {
        }, timeout: -5);
    }

    /**
     * Verifies that stream SSE accepts boundary one timeout.
     *
     * @return void
     */
    public function testStreamSseAcceptsBoundaryOneTimeout(): void
    {
        $sseData = "data: {\"status\": \"ok\"}\n\n";

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                1,
                $this->anything(),
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $eventCount = 0;
        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function () use (&$eventCount): void {
            $eventCount++;
        }, timeout: 1);

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event from the transport');
    }

    /**
     * Verifies that stream SSE logs request and completion context.
     *
     * @return void
     */
    public function testStreamSseLogsRequestAndCompletionContext(): void
    {
        $sseData = "data: {\"type\": \"complete\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $debugCalls = [];
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context) use (&$debugCalls): void {
                $debugCalls[] = ['message' => $message, 'context' => $context];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->streamSse('/test-stream', ['data' => 'test'], function (): void {
        });

        // Request log must include url and path
        $this->assertSame('Strands streamSse request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('path', $debugCalls[0]['context']);

        // Completion log must include url
        $this->assertSame('Strands streamSse complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('url', $debugCalls[1]['context']);
    }

    /**
     * Verifies that stream SSE data without space parses correctly.
     *
     * @return void
     */
    public function testStreamSseDataWithoutSpaceParsesCorrectly(): void
    {
        // SSE with "data:" (no space) — should still parse correctly
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
     * Verifies that stream SSE crlf in middle of event block.
     *
     * @return void
     */
    public function testStreamSseCrlfInMiddleOfEventBlock(): void
    {
        // CRLF line endings must be normalized to LF so "data:...\r\ndata:...\r\n\r\n"
        // is parsed as one event (two data lines), not split into separate events.
        // If \r\n normalization is removed, the \r\n\r\n becomes a double-newline
        // before the second data line, incorrectly splitting it into two events.
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // Two data lines with CRLF endings in the same event block
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

        // With correct CRLF normalization, this produces exactly one event
        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    /**
     * Verifies that stream SSE comment lines between data lines.
     *
     * @return void
     */
    public function testStreamSseCommentLinesBetweenDataLines(): void
    {
        // Comments between data lines should be skipped, not break the event
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

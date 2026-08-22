<?php

declare(strict_types=1);

/**
 * Exercises raw custom-endpoint events delivered to an application's SSE callback.
 *
 * It covers URLs, payloads, auth, framing, cancellation, errors, and timeout choices.
 * Failures here mean a custom live screen could miss events or stop incorrectly.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies streamSse() preserves app-owned payloads while applying shared transport safeguards.
 *
 * It protects custom live experiences that need raw JSON rather than typed StreamEvent objects.
 * Use these scenarios when changing raw framing, callback cancellation, auth, or error propagation.
 */
class StrandsClientStreamSseTest extends TestCase
{
    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the raw-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseData SSE fixture data yielded by the mock transport.
     * @return HttpTransport Value produced by the method.
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
     * Protects "stream sse sends correct url" so custom live screens receive predictable callbacks.
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
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
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
     * Protects "stream sse sends correct payload" so custom live screens receive predictable callbacks.
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
                    $decodedRequestPayload = json_decode($body, true);

                    return $decodedRequestPayload['file_base64'] === 'abc'
                        && $decodedRequestPayload['template'] === 'default';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
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
     * Protects "stream sse applies auth" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse parses events" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse preserves unknown fields" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse skips malformed json" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse handles multi line data" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse skips heartbeat comments" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse uses config timeout by default" so custom live screens receive predictable callbacks.
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
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
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
     * Protects "stream sse uses per request timeout" so custom live screens receive predictable callbacks.
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
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) {
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
     * Protects "stream sse cancels on false return" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse void callback continues" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse cancels across chunks" so custom live screens receive predictable callbacks.
     *
     * @return void
     */
    public function testStreamSseCancelsAcrossChunks(): void
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
                // The first chunk delivers the only raw event the user accepts before cancelling.
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // The later event must stay hidden because the callback already recorded the user's cancellation.
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
     * Protects "stream sse handles crlf line endings" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse handles crlf split across chunks" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse rejects an unbounded incomplete frame" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse handles chunked delivery" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse propagates transport error" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse throws on encoding failure" so custom live screens receive predictable callbacks.
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
        } catch (StrandsException $exception) {
            // A custom screen can accidentally submit NaN from a calculation; the caller should receive an encoding error before any request is sent.
            $this->assertStringContainsString('Failed to encode request payload', $exception->getMessage());
            $this->assertStringContainsString('Inf and NaN', $exception->getMessage());
        }
    }

    /**
     * Protects "stream sse rejects zero timeout" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse rejects negative timeout" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse accepts boundary one timeout" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse logs request and completion context" so custom live screens receive predictable callbacks.
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

        // Request diagnostics identify the custom route without recording the user's payload.
        $this->assertSame('Strands streamSse request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('path', $debugCalls[0]['context']);

        // Completion diagnostics retain the safe endpoint while omitting streamed event content.
        $this->assertSame('Strands streamSse complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('url', $debugCalls[1]['context']);
    }

    /**
     * Protects "stream sse data without space parses correctly" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse crlf in middle of event block" so custom live screens receive predictable callbacks.
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
     * Protects "stream sse comment lines between data lines" so custom live screens receive predictable callbacks.
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

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamResult;

/**
 * Verifies typed streaming stops, retries, times out, and reports interruptions as the caller requested.
 *
 * Use these tests when changing callbacks, cancellation, retry budgets, or terminal-event handling.
 * They protect live answer controls and the partial or final result returned to the app.
 */
class StrandsClientStreamControlTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic typed-streaming scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $fixtureName Non-empty SSE fixture filename under tests/Fixtures/.
     * @return string Captured SSE bytes; empty means no event reaches the app callback.
     */
    private function loadSseFixture(string $fixtureName): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $fixtureName);
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the typed-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseData Pre-built SSE payload; empty means the app receives no chunks.
     * @return HttpTransport Mocked transport with the chunk-by-chunk delivery wired up.
     */
    private function chunkedStreamingTransport(string $sseData): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData): void {
                // Each delimiter represents one update the wrapper could flush while the user watches the answer arrive.
                foreach (explode("\n\n", $sseData) as $chunk) {
                    // The trailing split after the final delimiter has no event and should not invoke the app callback.
                    if ($chunk === '') {
                        continue;
                    }
                    $streamResult = $onChunk->__invoke($chunk . "\n\n");
                    // Literal false means the user cancelled, so the transport must stop delivering later frames.
                    if ($streamResult === false) {
                        return;
                    }
                }
            });

        return $transport;
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the typed-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseFixture SSE bytes yielded by the mock; empty means the app receives no events.
     * @return HttpTransport Mock transport that delivers the supplied SSE fixture; never null.
     */
    private function createStreamingTransport(string $sseFixture): HttpTransport
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
            ) use ($sseFixture) {
                $onChunk->__invoke($sseFixture);
            });

        return $mock;
    }

    /**
     * Verifies stream() calls onEvent() for each event so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCallsOnEventForEachEvent(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $streamResult = $strandsClient->stream(
            message: 'Test message',
            onEvent: function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
            context: AgentContext::create()->withMetadata('persona', 'analyst'),
            sessionId: 'test-001',
        );

        $this->assertCount(3, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('Hello, ', $events[0]->text);
        $this->assertSame(StreamEventType::Complete, $events[2]->type);

        $this->assertInstanceOf(StreamResult::class, $streamResult);
        $this->assertSame('Hello, world!', $streamResult->text);
        $this->assertSame('test-001', $streamResult->sessionId);
        $this->assertSame(2, $streamResult->textEvents);
        $this->assertSame(3, $streamResult->totalEvents);
        $this->assertSame(10, $streamResult->usage->inputTokens);
        $this->assertSame(5, $streamResult->usage->outputTokens);
    }

    /**
     * Verifies stream() throws on missing terminal event so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamThrowsOnMissingTerminalEvent(): void
    {
        $incompleteSSE = "data: {\"type\": \"text\", \"content\": \"partial...\"}\n\n";
        $transport = $this->createStreamingTransport($incompleteSSE);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('terminal event');

        $strandsClient->stream(
            message: 'Test',
            onEvent: function () {
            },
        );
    }

    /**
     * Verifies stream() accepts error as terminal event so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamAcceptsErrorAsTerminalEvent(): void
    {
        $sseData = $this->loadSseFixture('sse-error-mid-stream.txt');
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
        );

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
        $this->assertSame('error', $streamResult->terminalType);
        $this->assertSame($events[1]->errorCode, $streamResult->errorCode);
        $this->assertSame($events[1]->errorMessage, $streamResult->errorMessage);
    }

    /**
     * Verifies stream() cancels on false return so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCancelsOnFalseReturn(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" world\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello world\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): bool {
                $events[] = $event;

                // Stop after the first live update so the app gets a partial result.
                return false;
            },
        );

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        // User cancellation returns the partial answer instead of misreporting a dropped connection.
        $this->assertSame('Hello', $streamResult->text);
    }

    /**
     * Verifies stream() returns a cancelled result without reporting an interruption so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCancelDoesNotThrowInterruptedException(): void
    {
        // A user-stopped stream needs no terminal event because the cancellation itself explains why live output ended.
        $sseData = "data: {\"type\": \"text\", \"content\": \"partial\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): bool {
                return false;
            },
        );

        $this->assertSame('partial', $streamResult->text);
    }

    /**
     * Verifies stream() void callback continues so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamVoidCallbackContinues(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertCount(2, $events);
        $this->assertSame('Hello', $streamResult->text);
    }

    /**
     * Verifies stream() cancels across chunks so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCancelsAcrossChunks(): void
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
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // This later chunk must stay hidden because the user already stopped live updates.
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"second\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): bool {
                $events[] = $event;

                return false;
            },
        );

        $this->assertCount(1, $events);
        $this->assertSame('first', $streamResult->text);
    }

    /**
     * Verifies stream() uses the per-call timeout override so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamWithTimeoutSecondsOverride(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                'http://localhost:8081/stream',
                $this->anything(),
                $this->anything(),
                300,
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
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 300,
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() rejects a zero-second timeout so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamTimeoutSecondsRejectsZero(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1');

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 0,
        );
    }

    /**
     * Verifies stream() uses the configured timeout when the override is null so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamTimeoutSecondsNullUsesDefault(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                60,
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
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 60),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: null,
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() accepts the one-second timeout boundary so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamTimeoutSecondsAcceptsBoundaryOne(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                1,
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
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 1,
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() cancellation callback returns false so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCancellationCallbackReturnsFalse(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"C\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"ABC\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";

        $transport = $this->chunkedStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $count = 0;
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function () use (&$count): bool {
                $count++;

                // Stop after the first live update so cancellation stays caller-controlled.
                return $count < 2;
            },
        );

        $this->assertTrue($streamResult->cancelled);
    }

    /**
     * Verifies stream() continues when the callback returns true so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCallbackReturnTrueContinuesStream(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): bool {
                return true;
            },
        );

        $this->assertFalse($streamResult->cancelled);
        $this->assertSame('Hello', $streamResult->text);
    }

    /**
     * Verifies stream() reports cancellation in the final result so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamResultCancelledStatusIsCorrect(): void
    {
        // A naturally completed answer reports false so the UI does not show a user-cancelled state.
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertFalse($streamResult->cancelled);
    }

    /**
     * Verifies stream() uses exactly the configured retry budget so live callbacks and the final result agree.
     *
     * @return void
     * @throws AgentErrorException When all retry attempts receive a retryable error.
     */
    public function testStreamRetryExhaustsMaxRetriesExactly(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->expects($this->any())->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \StrandsPhpClient\Exceptions\AgentErrorException('Unavailable', statusCode: 503);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        try {
            $strandsClient->invoke(message: 'Test');
            $this->fail('Expected AgentErrorException');
        } catch (\StrandsPhpClient\Exceptions\AgentErrorException $exception) {
            // A retryable 503 repeated past the configured budget is the practical failure the caller ultimately receives.
            // One initial request plus two configured retries gives the user three chances before the error returns.
            $this->assertSame(3, $callCount);
        }
    }

    /**
     * Verifies stream() reports an interruption when no event arrives so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamEmptyStreamThrowsInterrupted(): void
    {
        // A connection that closes without any event gives the user neither content nor a trustworthy completion signal.
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
                // Returning immediately reproduces a server that closes before sending the user's first update.
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('terminal event');

        $strandsClient->stream(message: 'Test', onEvent: function () {
        });
    }

    /**
     * Verifies stream() reports an interruption when only heartbeats arrive so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamOnlyHeartbeatsThrowsInterrupted(): void
    {
        // Heartbeats alone keep a connection alive but provide no answer or terminal state the UI can trust.
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
                $onChunk->__invoke(": heartbeat\n\n: keepalive\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessageMatches('/ended without a terminal event/');

        $strandsClient->stream(message: 'Test', onEvent: function () {
        });
    }

    /**
     * Verifies stream() reports an interruption when EOF leaves a partial event so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamPartialEventAtEofThrowsInterrupted(): void
    {
        // A truncated final frame reproduces a connection drop before the user's event becomes complete.
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
                $onChunk->__invoke('data: {"type": "text", "content": "partial"}');
                // Without the blank-line delimiter, the partial bytes must never reach the app callback.
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessageMatches('/ended without a terminal event/');

        $strandsClient->stream(message: 'Test', onEvent: function () {
        });
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Context\AgentInput;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamResult;

class StrandsClientStreamTest extends TestCase
{
    /**
     * Load a raw SSE fixture file as a string.
     *
     * @param string $name Fixture file name under tests/Fixtures/.
     * @return string Raw fixture contents.
     */
    private function loadSseFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Build a transport that delivers the SSE payload one event at a time and
     * stops as soon as the per-chunk callback returns false (so the client's
     * cancellation handshake can be exercised end-to-end without spelling out
     * the chunking loop in every test body).
     *
     * @param string $sseData Pre-built SSE payload to be chunked on \n\n boundaries.
     * @return HttpTransport Mocked transport with the chunk-by-chunk delivery wired up.
     */
    private function chunkedStreamingTransport(string $sseData): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData): void {
                foreach (explode("\n\n", $sseData) as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $streamResult = $onChunk->__invoke($chunk . "\n\n");
                    if ($streamResult === false) {
                        return;
                    }
                }
            });

        return $transport;
    }

    /**
     * Build a willReturnCallback closure that asserts a debug log's `$context`
     * contains the expected keys whenever its `$message` is in the map.
     * Test bodies keep their own `expects(...)->method('debug')` mock setup so
     * the assertion count stays visible to `test-quality.no-assertions`, while
     * the message-dispatch `if` lives here, outside the test scope.
     *
     * @param array<string, list<string>> $expectedKeysPerMessage Map of log message text → required context keys.
     * @return \Closure(string, array<string, mixed>=): void Callback suitable for `->willReturnCallback(...)`.
     */
    private function assertDebugContextKeys(array $expectedKeysPerMessage): \Closure
    {
        return function (string $message, array $context = []) use ($expectedKeysPerMessage): void {
            $required = $expectedKeysPerMessage[$message] ?? null;
            if ($required === null) {
                return;
            }
            foreach ($required as $key) {
                \PHPUnit\Framework\Assert::assertArrayHasKey($key, $context, "Log '{$message}' must include context key '{$key}'");
            }
        };
    }

    /**
     * Create streaming transport for the test scenario.
     *
     * @param string $sseFixture SSE fixture data yielded by the mock transport.
     * @return HttpTransport Value produced by the method.
     */
    private function createStreamingTransport(string $sseFixture): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseFixture) {
                $onChunk->__invoke($sseFixture);
            });

        return $mock;
    }

    /**
     * Verifies that stream calls on event for each event.
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
     * Verifies that stream sends correct URL.
     *
     * @return void
     */
    public function testStreamSendsCorrectUrl(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with(
                'http://localhost:8081/stream',
                $this->anything(),
                $this->anything(),
                120,
                10,
                $this->anything(),
            )
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function () {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies that stream returns stream result.
     *
     * @return void
     */
    public function testStreamReturnsStreamResult(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" there\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello there\", \"session_id\": \"s-1\", \"usage\": {\"input_tokens\": 20, \"output_tokens\": 10}, \"tools_used\": [], \"context_size\": 8192, \"projected_context_size\": 9216}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Hi',
            onEvent: function () {
            },
        );

        $this->assertSame('Hello there', $streamResult->text);
        $this->assertSame('s-1', $streamResult->sessionId);
        $this->assertSame(20, $streamResult->usage->inputTokens);
        $this->assertSame(10, $streamResult->usage->outputTokens);
        $this->assertSame(2, $streamResult->textEvents);
        $this->assertSame(3, $streamResult->totalEvents);
        $this->assertSame(8192, $streamResult->contextSize);
        $this->assertSame(9216, $streamResult->projectedContextSize);
    }

    /**
     * Verifies that stream throws on missing terminal event.
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
     * Verifies that stream accepts error as terminal event.
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
        $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
        );

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
    }

    /**
     * Verifies that stream parses tool use events.
     *
     * @return void
     */
    public function testStreamParsesToolUseEvents(): void
    {
        $sseData = "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n"
            . "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"found it\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"Based on the search...\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Based on the search...\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $strandsClient->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
        );

        $this->assertCount(4, $events);
        $this->assertSame(StreamEventType::ToolUse, $events[0]->type);
        $this->assertSame('search_kb', $events[0]->toolName);
        $this->assertSame(['query' => 'test'], $events[0]->toolInput);
        $this->assertSame(StreamEventType::ToolResult, $events[1]->type);
        $this->assertSame('search_kb', $events[1]->toolName);
        $this->assertSame('found it', $events[1]->toolResult);
        $this->assertSame(StreamEventType::Text, $events[2]->type);
        $this->assertSame(StreamEventType::Complete, $events[3]->type);
    }

    /**
     * Verifies that stream result default values.
     *
     * @return void
     */
    public function testStreamResultDefaultValues(): void
    {
        $streamResult = new StreamResult(text: '');

        $this->assertSame('', $streamResult->text);
        $this->assertNull($streamResult->sessionId);
        $this->assertSame(0, $streamResult->usage->inputTokens);
        $this->assertSame(0, $streamResult->usage->outputTokens);
        $this->assertSame([], $streamResult->toolsUsed);
        $this->assertSame(0, $streamResult->textEvents);
        $this->assertSame(0, $streamResult->totalEvents);
        $this->assertFalse($streamResult->cancelled);
    }

    /**
     * Verifies that stream logs debug messages.
     *
     * @return void
     */
    public function testStreamLogsDebugMessages(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
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

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        // Request log
        $this->assertSame('Strands stream request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('session_id', $debugCalls[0]['context']);

        // Completion log
        $this->assertSame('Strands stream complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('session_id', $debugCalls[1]['context']);
        $this->assertArrayHasKey('text_events', $debugCalls[1]['context']);
        $this->assertArrayHasKey('total_events', $debugCalls[1]['context']);
        $this->assertArrayHasKey('text_length', $debugCalls[1]['context']);
        $this->assertArrayHasKey('input_tokens', $debugCalls[1]['context']);
        $this->assertArrayHasKey('output_tokens', $debugCalls[1]['context']);
        $this->assertArrayHasKey('ttft_ms', $debugCalls[1]['context']);
    }

    /**
     * Verifies that stream tools used passed from complete event.
     *
     * @return void
     */
    public function testStreamToolsUsedPassedFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-2\", \"usage\": {\"input_tokens\": 30, \"output_tokens\": 15}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"name\": \"calc\"}]}\n\n";
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

        $this->assertCount(2, $streamResult->toolsUsed);
        $this->assertSame('search', $streamResult->toolsUsed[0]['name']);
        $this->assertSame(100, $streamResult->toolsUsed[0]['duration_ms']);
        $this->assertSame('calc', $streamResult->toolsUsed[1]['name']);
    }

    /**
     * Verifies that stream with no text events.
     *
     * @return void
     */
    public function testStreamWithNoTextEvents(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        $this->assertSame('', $streamResult->text);
        $this->assertSame(0, $streamResult->textEvents);
        $this->assertSame(1, $streamResult->totalEvents);
    }

    /**
     * Verifies that stream falls back to complete full text.
     *
     * @return void
     */
    public function testStreamFallsBackToCompleteFullText(): void
    {
        // Complete event has fullText but no Text events preceded it
        $sseData = "data: {\"type\": \"complete\", \"text\": \"Full response from agent\", \"session_id\": \"s-fb\", \"usage\": {\"input_tokens\": 5, \"output_tokens\": 3}, \"tools_used\": []}\n\n";
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

        $this->assertSame('Full response from agent', $streamResult->text);
        $this->assertSame(0, $streamResult->textEvents);
    }

    /**
     * Verifies that stream prefers accumulated text over full text.
     *
     * @return void
     */
    public function testStreamPrefersAccumulatedTextOverFullText(): void
    {
        // Both Text events and Complete.fullText present - accumulated text wins
        $sseData = "data: {\"type\": \"text\", \"content\": \"Streamed\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Streamed\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        $this->assertSame('Streamed', $streamResult->text);
        $this->assertSame(1, $streamResult->textEvents);
    }

    /**
     * Verifies that stream usage handles non int tokens.
     *
     * @return void
     */
    public function testStreamUsageHandlesNonIntTokens(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {\"input_tokens\": \"not_int\", \"output_tokens\": \"also_not\"}, \"tools_used\": []}\n\n";
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

        $this->assertSame(0, $streamResult->usage->inputTokens);
        $this->assertSame(0, $streamResult->usage->outputTokens);
    }

    /**
     * Verifies that stream complete event has stop reason.
     *
     * @return void
     */
    public function testStreamCompleteEventHasStopReason(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-1\", \"usage\": {}, \"tools_used\": [], \"stop_reason\": \"end_turn\"}\n\n";
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

        $this->assertSame(StopReason::EndTurn, $streamResult->stopReason);
    }

    /**
     * Verifies that stream stop reason defaults to null.
     *
     * @return void
     */
    /**
     * Verifies that StreamResult defaults each documented optional field to null
     * when the SSE complete event omits the corresponding data.
     *
     * @param string $propertyName Name of the StreamResult property expected to be null.
     * @return void
     */
    #[DataProvider('streamResultDefaultsToNullProvider')]
    public function testStreamResultDefaultsOptionalFieldToNull(string $propertyName): void
    {
        // Minimal complete event: no stop_reason, no text events (so no TTFT),
        // no guardrail trace — exercises every optional-field fallback path.
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        $this->assertNull($streamResult->{$propertyName});
    }

    /**
     * Cases for testStreamResultDefaultsOptionalFieldToNull().
     *
     * @return iterable<string, array{0: string}>
     */
    public static function streamResultDefaultsToNullProvider(): iterable
    {
        yield 'stopReason omitted from complete event' => ['stopReason'];
        yield 'timeToFirstTextTokenMs absent when no text events' => ['timeToFirstTextTokenMs'];
        yield 'guardrailTrace omitted from complete event' => ['guardrailTrace'];
    }

    /**
     * Verifies that stream cancels on false return.
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

                return false;  // cancel after first event
            },
        );

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        // Should return partial result without throwing StreamInterruptedException
        $this->assertSame('Hello', $streamResult->text);
    }

    /**
     * Verifies that stream cancel does not throw interrupted exception.
     *
     * @return void
     */
    public function testStreamCancelDoesNotThrowInterruptedException(): void
    {
        // Stream with no terminal event — but cancelled, so no exception
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
     * Verifies that stream void callback continues.
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
     * Verifies that stream cancels across chunks.
     *
     * @return void
     */
    public function testStreamCancelsAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // Second chunk — callback already cancelled, should be skipped
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
     * Verifies that stream with timeout seconds override.
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
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
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
     * Verifies that stream timeout seconds rejects zero.
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
     * Verifies that stream timeout seconds null uses default.
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
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
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
     * Verifies that stream timeout seconds accepts boundary one.
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
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
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
     * Verifies that stream result defaults time to first text token to null.
     *
     * @return void
     */
    public function testStreamResultDefaultsTimeToFirstTextTokenToNull(): void
    {
        $streamResult = new StreamResult(text: '');

        $this->assertNull($streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Verifies that stream records TTFT when text events present.
     *
     * @return void
     */
    public function testStreamRecordsTtftWhenTextEventsPresent(): void
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
            onEvent: function (): void {
            },
        );

        // TTFT should be a positive float when text events are present
        $this->assertNotNull($streamResult->timeToFirstTextTokenMs);
        $this->assertIsFloat($streamResult->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0.0, $streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Verifies that stream TTFT null when no text events.
     *
     * @return void
     */

    /**
     * Verifies that stream logs skipped events.
     *
     * @return void
     */
    public function testStreamLogsSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-with-unknown-event.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // Expect info-level log about skipped events
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'strands.stream.skipped_events',
                $this->callback(function (array $context): bool {
                    return $context['count'] === 1
                        && isset($context['hint']);
                }),
            );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies that stream does not log when no skipped events.
     *
     * @return void
     */
    public function testStreamDoesNotLogWhenNoSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // info() should NOT be called for skipped events
        $logger->expects($this->never())
            ->method('info');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies that stream TTFT included in debug log.
     *
     * @return void
     */
    public function testStreamTtftIncludedInDebugLog(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback($this->assertDebugContextKeys([
                'Strands stream complete' => ['ttft_ms'],
            ]));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies that stream usage hydrates cache tokens.
     *
     * @return void
     */
    public function testStreamUsageHydratesCacheTokens(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {\"input_tokens\": 100, \"output_tokens\": 50, \"cache_read_input_tokens\": 80, \"cache_write_input_tokens\": 20, \"latency_ms\": 1500, \"time_to_first_byte_ms\": 200}, \"tools_used\": []}\n\n";
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

        $this->assertSame(80, $streamResult->usage->cacheReadInputTokens);
        $this->assertSame(20, $streamResult->usage->cacheWriteInputTokens);
        $this->assertSame(1500, $streamResult->usage->latencyMs);
        $this->assertSame(200, $streamResult->usage->timeToFirstByteMs);
    }

    /**
     * Verifies that stream parses interrupts from complete event.
     *
     * @return void
     */
    public function testStreamParsesInterruptsFromCompleteEvent(): void
    {
        $sseData = $this->loadSseFixture('sse-interrupt-complete.txt');
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

        $this->assertTrue($streamResult->isInterrupted());
        $this->assertCount(1, $streamResult->interrupts);
        $this->assertInstanceOf(InterruptDetail::class, $streamResult->interrupts[0]);
        $this->assertSame('deploy', $streamResult->interrupts[0]->toolName);
        $this->assertSame('int-def-456', $streamResult->interrupts[0]->interruptId);
        $this->assertSame('Needs approval', $streamResult->interrupts[0]->reason);
        $this->assertSame(StopReason::Interrupt, $streamResult->stopReason);
    }

    /**
     * Verifies that stream no interrupts defaults empty.
     *
     * @return void
     */
    public function testStreamNoInterruptsDefaultsEmpty(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        $this->assertFalse($streamResult->isInterrupted());
        $this->assertSame([], $streamResult->interrupts);
    }

    /**
     * Verifies that stream parses guardrail trace from complete event.
     *
     * @return void
     */
    public function testStreamParsesGuardrailTraceFromCompleteEvent(): void
    {
        $sseData = $this->loadSseFixture('sse-guardrail-complete.txt');
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

        $this->assertNotNull($streamResult->guardrailTrace);
        $this->assertInstanceOf(GuardrailTrace::class, $streamResult->guardrailTrace);
        $this->assertSame('INTERVENED', $streamResult->guardrailTrace->action);
        $this->assertCount(1, $streamResult->guardrailTrace->assessments);
        $this->assertSame('Original output', $streamResult->guardrailTrace->modelOutput);
        $this->assertSame(StopReason::GuardrailIntervened, $streamResult->stopReason);
    }

    /**
     * Verifies that stream guardrail trace defaults to null.
     *
     * @return void
     */

    /**
     * Verifies that stream accepts agent input.
     *
     * @return void
     */
    public function testStreamAcceptsAgentInput(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"I see an image\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"I see an image\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                // Verify payload contains content blocks
                $decoded = json_decode($body, true);
                \PHPUnit\Framework\Assert::assertIsArray($decoded['message']);
                \PHPUnit\Framework\Assert::assertArrayHasKey('content', $decoded['message']);
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text("What's in this image?")
            ->withImage('base64data', 'image/png');

        $streamResult = $strandsClient->stream(
            message: $input,
            onEvent: function (): void {
            },
        );

        $this->assertSame('I see an image', $streamResult->text);
    }

    /**
     * Verifies that stream result defaults for new fields.
     *
     * @return void
     */
    public function testStreamResultDefaultsForNewFields(): void
    {
        $streamResult = new StreamResult(text: '');

        $this->assertFalse($streamResult->isInterrupted());
        $this->assertSame([], $streamResult->interrupts);
        $this->assertNull($streamResult->guardrailTrace);
    }

    /**
     * Verifies that stream logs debug on request and completion.
     *
     * @return void
     */
    public function testStreamLogsDebugOnRequestAndCompletion(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": \"s1\", \"usage\": {\"inputTokens\": 10, \"outputTokens\": 5}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback($this->assertDebugContextKeys([
                'Strands stream request' => ['url', 'session_id'],
                'Strands stream complete' => [
                    'session_id',
                    'text_events',
                    'total_events',
                    'text_length',
                    'input_tokens',
                    'output_tokens',
                    'ttft_ms',
                ],
            ]));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies that stream TTFT is positive when text events exist.
     *
     * @return void
     */
    public function testStreamTtftIsPositiveWhenTextEventsExist(): void
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
            onEvent: function (): void {
            },
        );

        $this->assertNotNull($streamResult->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0, $streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Verifies that stream extracts session ID from complete event.
     *
     * @return void
     */
    public function testStreamExtractsSessionIdFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": \"sess-xyz\", \"usage\": {\"input_tokens\": 5}, \"tools_used\": [{\"name\": \"calc\", \"duration_ms\": 100}], \"stop_reason\": \"end_turn\"}\n\n";
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

        $this->assertSame('sess-xyz', $streamResult->sessionId);
        $this->assertSame(5, $streamResult->usage->inputTokens);
        $this->assertCount(1, $streamResult->toolsUsed);
        $this->assertSame('calc', $streamResult->toolsUsed[0]['name']);
        $this->assertSame(100, $streamResult->toolsUsed[0]['duration_ms']);
        $this->assertSame(StopReason::EndTurn, $streamResult->stopReason);
    }

    /**
     * Verifies that stream cancellation callback returns false.
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

                return $count < 2; // Cancel after first event
            },
        );

        $this->assertTrue($streamResult->cancelled);
    }

    /**
     * Verifies that stream callback return true continues stream.
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
     * Verifies that stream does not accumulate thinking text as text events.
     *
     * @return void
     */
    public function testStreamDoesNotAccumulateThinkingTextAsTextEvents(): void
    {
        // Thinking events have text but type !== Text.
        // Only Text events should contribute to accumulatedText and textEvents count.
        $sseData = "data: {\"type\": \"thinking\", \"content\": \"Let me think...\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"Answer\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Answer\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        // Only "Answer" should be accumulated, not "Let me think..."
        $this->assertSame('Answer', $streamResult->text);
        $this->assertSame(1, $streamResult->textEvents);
        $this->assertSame(3, $streamResult->totalEvents);
    }

    /**
     * Verifies that stream result cancelled status is correct.
     *
     * @return void
     */
    public function testStreamResultCancelledStatusIsCorrect(): void
    {
        // Stream that completes normally should not be cancelled
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
     * Verifies that stream retry exhausts max retries exactly.
     *
     * @return void
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
        } catch (\StrandsPhpClient\Exceptions\AgentErrorException $e) {
            // 1 initial attempt + 2 retries = 3 total calls
            $this->assertSame(3, $callCount);
        }
    }

    /**
     * Verifies that stream empty stream throws interrupted.
     *
     * @return void
     */
    public function testStreamEmptyStreamThrowsInterrupted(): void
    {
        // Transport streams nothing — no events at all
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // Stream ends immediately without sending any chunks
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
     * Verifies that stream only heartbeats throws interrupted.
     *
     * @return void
     */
    public function testStreamOnlyHeartbeatsThrowsInterrupted(): void
    {
        // Stream contains only SSE comments (heartbeats) — no real events
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
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
     * Verifies that stream partial event at eof throws interrupted.
     *
     * @return void
     */
    public function testStreamPartialEventAtEofThrowsInterrupted(): void
    {
        // Stream ends with an incomplete event (no \n\n terminator)
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk->__invoke('data: {"type": "text", "content": "partial"}');
                // No \n\n so event never completes
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

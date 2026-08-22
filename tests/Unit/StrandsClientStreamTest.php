<?php

declare(strict_types=1);

/**
 * Exercises typed live agent responses from the first event through the final StreamResult.
 * It covers callback updates, cancellation, retries, terminal errors, usage, tools, and logs.
 * Failures here mean a chat UI could render the wrong live or final state.
 */

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

/**
 * Verifies stream() coordinates transport, parsing, callbacks, and the caller's final result.
 *
 * It protects the live text, cancellation, telemetry, session, and terminal state users experience.
 * Use these scenarios when changing any typed streaming orchestration in StrandsClient.
 */
class StrandsClientStreamTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic typed-streaming scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $name Fixture file name under tests/Fixtures/.
     * @return string Raw fixture contents.
     */
    private function loadSseFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the typed-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseData Pre-built SSE payload to be chunked on \n\n boundaries.
     * @return HttpTransport Mocked transport with the chunk-by-chunk delivery wired up.
     */
    private function chunkedStreamingTransport(string $sseData): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData): void {
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
     * Checks the caller-visible fields shared by several typed-streaming scenarios.
     * Use it to keep repeated expectations consistent and readable.
     *
     * @param array<string, list<string>> $expectedKeysPerMessage Map of log message text → required context keys.
     * @return \Closure(string, array<string, mixed>=): void Callback suitable for `->willReturnCallback(...)`.
     */
    private function assertDebugContextKeys(array $expectedKeysPerMessage): \Closure
    {
        return function (string $message, array $context = []) use ($expectedKeysPerMessage): void {
            $required = $expectedKeysPerMessage[$message] ?? null;
            // Ignore unrelated debug messages; each test lists only the caller-visible operation logs it owns.
            if ($required === null) {
                return;
            }
            // Every required key keeps an existing log consumer working after the 1.5 additions.
            foreach ($required as $key) {
                \PHPUnit\Framework\Assert::assertArrayHasKey($key, $context, "Log '{$message}' must include context key '{$key}'");
            }
        };
    }

    /**
     * Lists the safe fields expected from the related typed-streaming scenario.
     * Use it when several tests must enforce the same user-facing diagnostic shape.
     *
     * @return array<string, list<string>> Required context keys keyed by log message.
     */
    private function expectedStreamDebugContextKeys(): array
    {
        return [
            'Strands stream request' => ['url', 'session_id'],
            'Strands stream complete' => [
                'session_id',
                'text_events',
                'total_events',
                'text_length',
                'input_tokens',
                'output_tokens',
                'ttft_ms',
                'tools_used',
                'cancelled',
            ],
        ];
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the typed-streaming scenario must inspect requests or delivery order.
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
     * Covers "stream calls on event for each event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream sends correct url" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream returns stream result" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream throws on missing terminal event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream accepts error as terminal event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream parses tool use events" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream result default values" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream logs debug messages" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamLogsDebugMessages(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback($this->assertDebugContextKeys($this->expectedStreamDebugContextKeys()));

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
     * Covers "stream tools used passed from complete event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamToolsUsedPassedFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-2\", \"usage\": {\"input_tokens\": 30, \"output_tokens\": 15}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100, \"input\": {\"query\": \"docs\"}, \"result\": {\"count\": 2}}, {\"name\": \"calc\"}]}\n\n";
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
        $this->assertSame(['query' => 'docs'], $streamResult->toolsUsed[0]['input']);
        $this->assertSame(['count' => 2], $streamResult->toolsUsed[0]['result']);
        $this->assertSame('calc', $streamResult->toolsUsed[1]['name']);
    }

    /**
     * Covers "stream with no text events" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream falls back to complete full text" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamFallsBackToCompleteFullText(): void
    {
        // A wrapper may send only final text; the answer screen still needs that fallback content.
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
     * Covers "stream prefers accumulated text over full text" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamPrefersAccumulatedTextOverFullText(): void
    {
        // When live text already reached the user, the final result keeps exactly that sequence instead of duplicating terminal full text.
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
     * Covers "stream usage handles non int tokens" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream complete event has stop reason" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
        $this->assertSame('end_turn', $streamResult->rawStopReason);
    }

    /**
     * Covers "stream result defaults optional field to null" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @param string $propertyName Name of the StreamResult property expected to be null.
     * @return void
     */
    #[DataProvider('streamResultDefaultsToNullProvider')]
    public function testStreamResultDefaultsOptionalFieldToNull(string $propertyName): void
    {
        // A minimal completion with no text, stop reason, latency, or guardrail detail exercises every empty final-screen fallback.
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
     * Supplies the input variants for the related typed-streaming scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: string}> Missing stream fields that should stay null for app callers.
     */
    public static function streamResultDefaultsToNullProvider(): iterable
    {
        yield 'stopReason omitted from complete event' => ['stopReason'];
        yield 'rawStopReason omitted from complete event' => ['rawStopReason'];
        yield 'timeToFirstTextTokenMs absent when no text events' => ['timeToFirstTextTokenMs'];
        yield 'guardrailTrace omitted from complete event' => ['guardrailTrace'];
    }

    /**
     * Covers "stream cancels on false return" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream cancel does not throw interrupted exception" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream void callback continues" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream cancels across chunks" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamCancelsAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
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
     * Covers "stream with timeout seconds override" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream timeout seconds rejects zero" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream timeout seconds null uses default" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream timeout seconds accepts boundary one" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream result defaults time to first text token to null" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamResultDefaultsTimeToFirstTextTokenToNull(): void
    {
        $streamResult = new StreamResult(text: '');

        $this->assertNull($streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Covers "stream records ttft when text events present" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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

        // Receiving text gives the final result a nonnegative client-measured time-to-first-text value.
        $this->assertNotNull($streamResult->timeToFirstTextTokenMs);
        $this->assertIsFloat($streamResult->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0.0, $streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Covers "stream logs skipped events" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamLogsSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-with-unknown-event.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // An unknown server event produces an upgrade hint for operators without interrupting the user's answer.
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
     * Covers "stream does not log when no skipped events" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamDoesNotLogWhenNoSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // A fully recognized stream produces no compatibility warning for operators.
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
     * Covers "stream debug log includes token timing field" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamDebugLogIncludesTokenTimingField(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
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

        $this->assertSame('Strands stream complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('ttft_ms', $debugCalls[1]['context']);
        $this->assertNotNull($debugCalls[1]['context']['ttft_ms']);
    }

    /**
     * Covers "stream usage hydrates cache tokens" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamUsageHydratesCacheTokens(): void
    {
        $expectedLatencyMs = 1501;
        $expectedTimeToFirstByteMs = 200;
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {\"input_tokens\": 100, \"output_tokens\": 50, \"cache_read_input_tokens\": 80, \"cache_write_input_tokens\": 20, \"latency_ms\": 1500.5, \"time_to_first_byte_ms\": 200.25}, \"tools_used\": []}\n\n";
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
        $this->assertSame($expectedLatencyMs, $streamResult->usage->latencyMs);
        $this->assertSame($expectedTimeToFirstByteMs, $streamResult->usage->timeToFirstByteMs);
    }

    /**
     * Covers "stream parses interrupts from complete event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream no interrupts defaults empty" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream parses guardrail trace from complete event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream accepts agent input" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
                // The transport must receive the text and attachment blocks assembled by the user's rich-input screen.
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
     * Covers "stream result defaults for new fields" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream logs debug on request and completion" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
            ->willReturnCallback($this->assertDebugContextKeys($this->expectedStreamDebugContextKeys()));

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
     * Covers "stream ttft is positive when text events exist" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream extracts session id from complete event" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream cancellation callback returns false" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream callback return true continues stream" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream does not accumulate thinking text as text events" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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

        // The final visible answer contains only text events, not the agent's separate thinking update.
        $this->assertSame('Answer', $streamResult->text);
        $this->assertSame(1, $streamResult->textEvents);
        $this->assertSame(3, $streamResult->totalEvents);
    }

    /**
     * Covers "stream result cancelled status is correct" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream retry exhausts max retries exactly" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
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
     * Covers "stream empty stream throws interrupted" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamEmptyStreamThrowsInterrupted(): void
    {
        // A connection that closes without any event gives the user neither content nor a trustworthy completion signal.
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
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
     * Covers "stream only heartbeats throws interrupted" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamOnlyHeartbeatsThrowsInterrupted(): void
    {
        // Heartbeats alone keep a connection alive but provide no answer or terminal state the UI can trust.
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
     * Covers "stream partial event at eof throws interrupted" so live updates and the final answer stay consistent.
     * Use this regression case when typed stream aggregation or cancellation changes.
     *
     * @return void
     */
    public function testStreamPartialEventAtEofThrowsInterrupted(): void
    {
        // A truncated final frame reproduces a connection drop before the user's event becomes complete.
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
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

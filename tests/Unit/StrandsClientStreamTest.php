<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentInput;
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
     * Verifies stream() sends the correct URL so live callbacks and the final result agree.
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
            onEvent: function () {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() returns a complete StreamResult so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamReturnsStreamResult(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" there\"}\n\n"
            . 'data: {"type": "complete", "text": "Hello there", "session_id": "s-1", '
            . '"usage": {"input_tokens": 20, "output_tokens": 10}, "tools_used": [], '
            . '"context_size": 8192, "projected_context_size": 9216}' . "\n\n";
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
     * Verifies stream() delivers typed tool-use events so live callbacks and the final result agree.
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
     * Verifies stream() returns safe defaults when terminal fields are absent so live callbacks and the final result agree.
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
     * Verifies stream() preserves terminal tool summaries so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamToolsUsedPassedFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . 'data: {"type": "complete", "text": "Done", "session_id": "s-2", '
            . '"usage": {"input_tokens": 30, "output_tokens": 15}, '
            . '"tools_used": [{"name": "search", "duration_ms": 100, '
            . '"input": {"query": "docs"}, "result": {"count": 2}}, {"name": "calc"}]}' . "\n\n";
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
     * Verifies stream() can finish without a text event so live callbacks and the final result agree.
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
     * Verifies stream() falls back to complete full text so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamFallsBackToCompleteFullText(): void
    {
        // A wrapper may send only final text; the answer screen still needs that fallback content.
        $sseData = 'data: {"type": "complete", "text": "Full response from agent", '
            . '"session_id": "s-fb", "usage": {"input_tokens": 5, "output_tokens": 3}, '
            . '"tools_used": []}' . "\n\n";
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
     * Verifies stream() prefers accumulated text over full text so live callbacks and the final result agree.
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
     * Verifies stream() gives malformed token counts safe defaults so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamUsageHandlesNonIntTokens(): void
    {
        $sseData = 'data: {"type": "complete", "text": "", "session_id": null, '
            . '"usage": {"input_tokens": "not_int", "output_tokens": "also_not"}, '
            . '"tools_used": []}' . "\n\n";
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
     * Verifies stream() preserves the terminal stop reason so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamCompleteEventHasStopReason(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . 'data: {"type": "complete", "text": "Done", "session_id": "s-1", '
            . '"usage": {}, "tools_used": [], "stop_reason": "end_turn"}' . "\n\n";
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
     * Verifies stream() defaults each omitted optional result field to null so live callbacks and the final result agree.
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
     * Lists optional final-result fields that remain null when a completion omits them.
     * An empty provider would leave safe stream-result defaults unverified.
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
     * Verifies stream() usage hydrates cache tokens so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamUsageHydratesCacheTokens(): void
    {
        $expectedLatencyMs = 1501;
        $expectedTimeToFirstByteMs = 200;
        $sseData = 'data: {"type": "complete", "text": "", "session_id": null, '
            . '"usage": {"input_tokens": 100, "output_tokens": 50, '
            . '"cache_read_input_tokens": 80, "cache_write_input_tokens": 20, '
            . '"latency_ms": 1500.5, "time_to_first_byte_ms": 200.25}, '
            . '"tools_used": []}' . "\n\n";
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
     * Verifies stream() preserves terminal interrupts so live callbacks and the final result agree.
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
     * Verifies stream() defaults interrupts to an empty list when none arrive so live callbacks and the final result agree.
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
     * Verifies stream() preserves terminal guardrail details so live callbacks and the final result agree.
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
     * Verifies stream() accepts agent input so live callbacks and the final result agree.
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
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData) {
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
     * Verifies stream() gives newly added result fields compatible defaults so live callbacks and the final result agree.
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
     * Verifies stream() preserves the terminal session ID so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamExtractsSessionIdFromCompleteEvent(): void
    {
        $sseData = 'data: {"type": "complete", "text": "Hi", "session_id": "sess-xyz", '
            . '"usage": {"input_tokens": 5}, '
            . '"tools_used": [{"name": "calc", "duration_ms": 100}], '
            . '"stop_reason": "end_turn"}' . "\n\n";
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
     * Verifies stream() keeps reasoning text out of the visible answer.
     * This keeps live updates consistent with the final result returned to the caller.
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

}

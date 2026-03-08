<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

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
    private function createStreamingTransport(string $sseFixture): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseFixture) {
                $onChunk($sseFixture);
            });

        return $mock;
    }

    public function testStreamCallsOnEventForEachEvent(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $result = $client->stream(
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

        $this->assertInstanceOf(StreamResult::class, $result);
        $this->assertSame('Hello, world!', $result->text);
        $this->assertSame('test-001', $result->sessionId);
        $this->assertSame(2, $result->textEvents);
        $this->assertSame(3, $result->totalEvents);
        $this->assertSame(10, $result->usage->inputTokens);
        $this->assertSame(5, $result->usage->outputTokens);
    }

    public function testStreamSendsCorrectUrl(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->stream(
            message: 'Test',
            onEvent: function () {
            },
        );
    }

    public function testStreamReturnsStreamResult(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" there\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello there\", \"session_id\": \"s-1\", \"usage\": {\"input_tokens\": 20, \"output_tokens\": 10}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Hi',
            onEvent: function () {
            },
        );

        $this->assertSame('Hello there', $result->text);
        $this->assertSame('s-1', $result->sessionId);
        $this->assertSame(20, $result->usage->inputTokens);
        $this->assertSame(10, $result->usage->outputTokens);
        $this->assertSame(2, $result->textEvents);
        $this->assertSame(3, $result->totalEvents);
    }

    public function testStreamThrowsOnMissingTerminalEvent(): void
    {
        $incompleteSSE = "data: {\"type\": \"text\", \"content\": \"partial...\"}\n\n";
        $transport = $this->createStreamingTransport($incompleteSSE);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('terminal event');

        $client->stream(
            message: 'Test',
            onEvent: function () {
            },
        );
    }

    public function testStreamAcceptsErrorAsTerminalEvent(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-error-mid-stream.txt');
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
        );

        $this->assertCount(2, $events);
        $this->assertSame(StreamEventType::Error, $events[1]->type);
    }

    public function testStreamParsesToolUseEvents(): void
    {
        $sseData = "data: {\"type\": \"tool_use\", \"tool_name\": \"search_kb\", \"tool_input\": {\"query\": \"test\"}}\n\n"
            . "data: {\"type\": \"tool_result\", \"tool_name\": \"search_kb\", \"result\": \"found it\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"Based on the search...\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Based on the search...\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->stream(
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

    public function testStreamResultDefaultValues(): void
    {
        $result = new StreamResult(text: '');

        $this->assertSame('', $result->text);
        $this->assertNull($result->sessionId);
        $this->assertSame(0, $result->usage->inputTokens);
        $this->assertSame(0, $result->usage->outputTokens);
        $this->assertSame([], $result->toolsUsed);
        $this->assertSame(0, $result->textEvents);
        $this->assertSame(0, $result->totalEvents);
        $this->assertFalse($result->cancelled);
    }

    public function testStreamLogsDebugMessages(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $debugCalls = [];
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context) use (&$debugCalls): void {
                $debugCalls[] = ['message' => $message, 'context' => $context];
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->stream(
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

    public function testStreamToolsUsedPassedFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-2\", \"usage\": {\"input_tokens\": 30, \"output_tokens\": 15}, \"tools_used\": [{\"name\": \"search\", \"duration_ms\": 100}, {\"name\": \"calc\"}]}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertCount(2, $result->toolsUsed);
        $this->assertSame('search', $result->toolsUsed[0]['name']);
        $this->assertSame(100, $result->toolsUsed[0]['duration_ms']);
        $this->assertSame('calc', $result->toolsUsed[1]['name']);
    }

    public function testStreamWithNoTextEvents(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame('', $result->text);
        $this->assertSame(0, $result->textEvents);
        $this->assertSame(1, $result->totalEvents);
    }

    public function testStreamFallsBackToCompleteFullText(): void
    {
        // Complete event has fullText but no Text events preceded it
        $sseData = "data: {\"type\": \"complete\", \"text\": \"Full response from agent\", \"session_id\": \"s-fb\", \"usage\": {\"input_tokens\": 5, \"output_tokens\": 3}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame('Full response from agent', $result->text);
        $this->assertSame(0, $result->textEvents);
    }

    public function testStreamPrefersAccumulatedTextOverFullText(): void
    {
        // Both Text events and Complete.fullText present - accumulated text wins
        $sseData = "data: {\"type\": \"text\", \"content\": \"Streamed\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Streamed\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame('Streamed', $result->text);
        $this->assertSame(1, $result->textEvents);
    }

    public function testStreamUsageHandlesNonIntTokens(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {\"input_tokens\": \"not_int\", \"output_tokens\": \"also_not\"}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame(0, $result->usage->inputTokens);
        $this->assertSame(0, $result->usage->outputTokens);
    }

    public function testStreamCompleteEventHasStopReason(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Done\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Done\", \"session_id\": \"s-1\", \"usage\": {}, \"tools_used\": [], \"stop_reason\": \"end_turn\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame(StopReason::EndTurn, $result->stopReason);
    }

    public function testStreamStopReasonDefaultsToNull(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNull($result->stopReason);
    }

    public function testStreamCancelsOnFalseReturn(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" world\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello world\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $result = $client->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): bool {
                $events[] = $event;

                return false;  // cancel after first event
            },
        );

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        // Should return partial result without throwing StreamInterruptedException
        $this->assertSame('Hello', $result->text);
    }

    public function testStreamCancelDoesNotThrowInterruptedException(): void
    {
        // Stream with no terminal event — but cancelled, so no exception
        $sseData = "data: {\"type\": \"text\", \"content\": \"partial\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): bool {
                return false;
            },
        );

        $this->assertSame('partial', $result->text);
    }

    public function testStreamVoidCallbackContinues(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $result = $client->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertCount(2, $events);
        $this->assertSame('Hello', $result->text);
    }

    public function testStreamCancelsAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // Second chunk — callback already cancelled, should be skipped
                $onChunk("data: {\"type\": \"text\", \"content\": \"second\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $result = $client->stream(
            message: 'Test',
            onEvent: function (StreamEvent $event) use (&$events): bool {
                $events[] = $event;

                return false;
            },
        );

        $this->assertCount(1, $events);
        $this->assertSame('first', $result->text);
    }

    public function testStreamWithTimeoutSecondsOverride(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 300,
        );
    }

    public function testStreamTimeoutSecondsRejectsZero(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1');

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 0,
        );
    }

    public function testStreamTimeoutSecondsNullUsesDefault(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 60),
            transport: $transport,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: null,
        );
    }

    public function testStreamTimeoutSecondsAcceptsBoundaryOne(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
            timeoutSeconds: 1,
        );
    }

    public function testStreamResultDefaultsTimeToFirstTextTokenToNull(): void
    {
        $result = new StreamResult(text: '');

        $this->assertNull($result->timeToFirstTextTokenMs);
    }

    public function testStreamRecordsTtftWhenTextEventsPresent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        // TTFT should be a positive float when text events are present
        $this->assertNotNull($result->timeToFirstTextTokenMs);
        $this->assertIsFloat($result->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0.0, $result->timeToFirstTextTokenMs);
    }

    public function testStreamTtftNullWhenNoTextEvents(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNull($result->timeToFirstTextTokenMs);
    }

    public function testStreamLogsSkippedEvents(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-with-unknown-event.txt');
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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    public function testStreamDoesNotLogWhenNoSkippedEvents(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // info() should NOT be called for skipped events
        $logger->expects($this->never())
            ->method('info');

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    public function testStreamTtftIncludedInDebugLog(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // The second debug call is "Strands stream complete" and should include ttft_ms
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context = []) {
                if ($message === 'Strands stream complete') {
                    \PHPUnit\Framework\Assert::assertArrayHasKey('ttft_ms', $context);
                    \PHPUnit\Framework\Assert::assertNotNull($context['ttft_ms']);
                }
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    public function testStreamUsageHydratesCacheTokens(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {\"input_tokens\": 100, \"output_tokens\": 50, \"cache_read_input_tokens\": 80, \"cache_write_input_tokens\": 20, \"latency_ms\": 1500, \"time_to_first_byte_ms\": 200}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame(80, $result->usage->cacheReadInputTokens);
        $this->assertSame(20, $result->usage->cacheWriteInputTokens);
        $this->assertSame(1500, $result->usage->latencyMs);
        $this->assertSame(200, $result->usage->timeToFirstByteMs);
    }

    public function testStreamParsesInterruptsFromCompleteEvent(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-interrupt-complete.txt');
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertTrue($result->isInterrupted());
        $this->assertCount(1, $result->interrupts);
        $this->assertInstanceOf(InterruptDetail::class, $result->interrupts[0]);
        $this->assertSame('deploy', $result->interrupts[0]->toolName);
        $this->assertSame('int-def-456', $result->interrupts[0]->interruptId);
        $this->assertSame('Needs approval', $result->interrupts[0]->reason);
        $this->assertSame(StopReason::Interrupt, $result->stopReason);
    }

    public function testStreamNoInterruptsDefaultsEmpty(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertFalse($result->isInterrupted());
        $this->assertSame([], $result->interrupts);
    }

    public function testStreamParsesGuardrailTraceFromCompleteEvent(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-guardrail-complete.txt');
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNotNull($result->guardrailTrace);
        $this->assertInstanceOf(GuardrailTrace::class, $result->guardrailTrace);
        $this->assertSame('INTERVENED', $result->guardrailTrace->action);
        $this->assertCount(1, $result->guardrailTrace->assessments);
        $this->assertSame('Original output', $result->guardrailTrace->modelOutput);
        $this->assertSame(StopReason::GuardrailIntervened, $result->stopReason);
    }

    public function testStreamGuardrailTraceDefaultsToNull(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNull($result->guardrailTrace);
    }

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text("What's in this image?")
            ->withImage('base64data', 'image/png');

        $result = $client->stream(
            message: $input,
            onEvent: function (): void {
            },
        );

        $this->assertSame('I see an image', $result->text);
    }

    public function testStreamResultDefaultsForNewFields(): void
    {
        $result = new StreamResult(text: '');

        $this->assertFalse($result->isInterrupted());
        $this->assertSame([], $result->interrupts);
        $this->assertNull($result->guardrailTrace);
    }

    public function testStreamLogsDebugOnRequestAndCompletion(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": \"s1\", \"usage\": {\"inputTokens\": 10, \"outputTokens\": 5}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context): void {
                if ($message === 'Strands stream request') {
                    $this->assertArrayHasKey('url', $context);
                    $this->assertArrayHasKey('session_id', $context);
                } elseif ($message === 'Strands stream complete') {
                    $this->assertArrayHasKey('session_id', $context);
                    $this->assertArrayHasKey('text_events', $context);
                    $this->assertArrayHasKey('total_events', $context);
                    $this->assertArrayHasKey('text_length', $context);
                    $this->assertArrayHasKey('input_tokens', $context);
                    $this->assertArrayHasKey('output_tokens', $context);
                    $this->assertArrayHasKey('ttft_ms', $context);
                }
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    public function testStreamTtftIsPositiveWhenTextEventsExist(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNotNull($result->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0, $result->timeToFirstTextTokenMs);
    }

    public function testStreamExtractsSessionIdFromCompleteEvent(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": \"sess-xyz\", \"usage\": {\"input_tokens\": 5}, \"tools_used\": [{\"name\": \"calc\", \"duration_ms\": 100}], \"stop_reason\": \"end_turn\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame('sess-xyz', $result->sessionId);
        $this->assertSame(5, $result->usage->inputTokens);
        $this->assertCount(1, $result->toolsUsed);
        $this->assertSame('calc', $result->toolsUsed[0]['name']);
        $this->assertSame(100, $result->toolsUsed[0]['duration_ms']);
        $this->assertSame(StopReason::EndTurn, $result->stopReason);
    }

    public function testStreamCancellationCallbackReturnsFalse(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"A\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"B\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"C\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"ABC\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData): void {
                // Simulate chunked delivery — transport respects false return
                foreach (explode("\n\n", $sseData) as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $result = $onChunk($chunk . "\n\n");
                    if ($result === false) {
                        return;
                    }
                }
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $count = 0;
        $result = $client->stream(
            message: 'Test',
            onEvent: function () use (&$count): bool {
                $count++;

                return $count < 2; // Cancel after first event
            },
        );

        $this->assertTrue($result->cancelled);
    }

    public function testStreamCallbackReturnTrueContinuesStream(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): bool {
                return true;
            },
        );

        $this->assertFalse($result->cancelled);
        $this->assertSame('Hello', $result->text);
    }

    public function testStreamDoesNotAccumulateThinkingTextAsTextEvents(): void
    {
        // Thinking events have text but type !== Text.
        // Only Text events should contribute to accumulatedText and textEvents count.
        $sseData = "data: {\"type\": \"thinking\", \"content\": \"Let me think...\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"Answer\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Answer\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        // Only "Answer" should be accumulated, not "Let me think..."
        $this->assertSame('Answer', $result->text);
        $this->assertSame(1, $result->textEvents);
        $this->assertSame(3, $result->totalEvents);
    }

    public function testStreamResultCancelledStatusIsCorrect(): void
    {
        // Stream that completes normally should not be cancelled
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertFalse($result->cancelled);
    }

    public function testStreamRetryExhaustsMaxRetriesExactly(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \StrandsPhpClient\Exceptions\AgentErrorException('Unavailable', statusCode: 503);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        try {
            $client->invoke(message: 'Test');
            $this->fail('Expected AgentErrorException');
        } catch (\StrandsPhpClient\Exceptions\AgentErrorException $e) {
            // 1 initial attempt + 2 retries = 3 total calls
            $this->assertSame(3, $callCount);
        }
    }

    public function testStreamEmptyStreamThrowsInterrupted(): void
    {
        // Transport streams nothing — no events at all
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // Stream ends immediately without sending any chunks
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('terminal event');

        $client->stream(message: 'Test', onEvent: function () {
        });
    }

    public function testStreamOnlyHeartbeatsThrowsInterrupted(): void
    {
        // Stream contains only SSE comments (heartbeats) — no real events
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk(": heartbeat\n\n: keepalive\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);

        $client->stream(message: 'Test', onEvent: function () {
        });
    }

    public function testStreamPartialEventAtEofThrowsInterrupted(): void
    {
        // Stream ends with an incomplete event (no \n\n terminator)
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                $onChunk('data: {"type": "text", "content": "partial"}');
                // No \n\n so event never completes
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StreamInterruptedException::class);

        $client->stream(message: 'Test', onEvent: function () {
        });
    }
}

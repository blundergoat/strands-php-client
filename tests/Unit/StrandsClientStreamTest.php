<?php

declare(strict_types=1);

namespace Strands\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Strands\Config\StrandsConfig;
use Strands\Context\AgentContext;
use Strands\Exceptions\StreamInterruptedException;
use Strands\Http\HttpTransport;
use Strands\StrandsClient;
use Strands\Streaming\StreamEvent;
use Strands\Streaming\StreamEventType;
use Strands\Streaming\StreamResult;

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
    }

    public function testStreamLogsDebugMessages(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug');

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
        // Both Text events and Complete.fullText present — accumulated text wins
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
}

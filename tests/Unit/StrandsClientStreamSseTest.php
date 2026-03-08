<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

class StrandsClientStreamSseTest extends TestCase
{
    private function createStreamingTransport(string $sseData): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        return $mock;
    }

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
                $onChunk("data: {\"type\": \"complete\", \"text\": \"done\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $client->streamSse('/file-summarise-stream', ['file_base64' => 'abc'], function () {
        });
    }

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
                $onChunk("data: {\"type\": \"complete\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->streamSse('/file-summarise-stream', [
            'file_base64' => 'abc',
            'template' => 'default',
        ], function () {
        });
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $client->streamSse('/file-summarise-stream', ['file_base64' => 'abc'], function () {
        });
    }

    public function testStreamSseParsesEvents(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \" world\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello world\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
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

    public function testStreamSsePreservesUnknownFields(): void
    {
        $sseData = "data: {\"type\": \"complete\", \"text\": \"done\", \"verification\": {\"score\": 95}, \"model\": \"claude-3\", \"metadata\": {\"custom\": true}}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('done', $events[0]['text']);
        $this->assertSame(['score' => 95], $events[0]['verification']);
        $this->assertSame('claude-3', $events[0]['model']);
        $this->assertSame(['custom' => true], $events[0]['metadata']);
    }

    public function testStreamSseSkipsMalformedJson(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {not valid json}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"third\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('third', $events[1]['content']);
    }

    public function testStreamSseHandlesMultiLineData(): void
    {
        $sseData = "data: {\"type\": \"text\",\ndata:  \"content\": \"hello\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    public function testStreamSseSkipsHeartbeatComments(): void
    {
        $sseData = ": heartbeat\n\n"
            . "data: {\"type\": \"text\", \"content\": \"hello\"}\n\n"
            . ": another heartbeat\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]['content']);
    }

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
                $onChunk("data: {\"type\": \"complete\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $client->streamSse('/test-stream', ['data' => 'test'], function () {
        });
    }

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
                $onChunk("data: {\"type\": \"complete\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $client->streamSse('/test-stream', ['data' => 'test'], function () {
        }, timeout: 15);
    }

    public function testStreamSseCancelsOnFalseReturn(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"third\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): bool {
            $events[] = $event;

            return count($events) < 2;  // cancel after 2nd event
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('second', $events[1]['content']);
    }

    public function testStreamSseVoidCallbackContinues(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n"
            . "data: {\"type\": \"complete\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(3, $events);
    }

    public function testStreamSseCancelsAcrossChunks(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // First chunk delivers one event
                $onChunk("data: {\"type\": \"text\", \"content\": \"first\"}\n\n");
                // Second chunk delivers another — but callback already cancelled
                $onChunk("data: {\"type\": \"text\", \"content\": \"second\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events): bool {
            $events[] = $event;

            return false;  // cancel immediately
        });

        $this->assertCount(1, $events);
        $this->assertSame('first', $events[0]['content']);
    }

    public function testStreamSseHandlesCrlfLineEndings(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"hello\"}\r\n\r\n"
            . "data: {\"type\": \"complete\", \"text\": \"hello\"}\r\n\r\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('hello', $events[0]['content']);
        $this->assertSame('complete', $events[1]['type']);
    }

    public function testStreamSseHandlesChunkedDelivery(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // SSE event split across two TCP chunks
                $onChunk('data: {"type":');
                $onChunk(" \"text\", \"content\": \"hello\"}\n\n");
                // Second complete event in one chunk
                $onChunk("data: {\"type\": \"complete\", \"text\": \"hello\"}\n\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test-stream', ['data' => 'test'], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
        $this->assertSame('complete', $events[1]['type']);
    }

    public function testStreamSsePropagatesTransportError(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willThrowException(new AgentErrorException('Internal Server Error', statusCode: 500));

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $client->streamSse('/test-stream', ['data' => 'test'], function () {
        });
    }

    public function testStreamSseThrowsOnEncodingFailure(): void
    {
        $transport = $this->createMock(HttpTransport::class);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        try {
            $client->streamSse('/test-stream', ['bad_value' => NAN], function () {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertStringContainsString('Failed to encode request payload', $e->getMessage());
            $this->assertStringContainsString('Inf and NaN', $e->getMessage());
        }
    }

    public function testStreamSseRejectsZeroTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $client->streamSse('/test-stream', ['data' => 'test'], function () {
        }, timeout: 0);
    }

    public function testStreamSseRejectsNegativeTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $client->streamSse('/test-stream', ['data' => 'test'], function () {
        }, timeout: -5);
    }

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
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->streamSse('/test-stream', ['data' => 'test'], function (): void {
        }, timeout: 1);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->streamSse('/test-stream', ['data' => 'test'], function (): void {
        });

        // Request log must include url and path
        $this->assertSame('Strands streamSse request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('path', $debugCalls[0]['context']);

        // Completion log must include url
        $this->assertSame('Strands streamSse complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('url', $debugCalls[1]['context']);
    }

    public function testStreamSseDataWithoutSpaceParsesCorrectly(): void
    {
        // SSE with "data:" (no space) — should still parse correctly
        $sseData = "data:{\"type\": \"text\", \"content\": \"hello\"}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertSame('hello', $events[0]['content']);
    }

    public function testStreamSseCrlfInMiddleOfEventBlock(): void
    {
        // CRLF line endings must be normalized to LF so "data:...\r\ndata:...\r\n\r\n"
        // is parsed as one event (two data lines), not split into separate events.
        // If \r\n normalization is removed, the \r\n\r\n becomes a double-newline
        // before the second data line, incorrectly splitting it into two events.
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) {
                // Two data lines with CRLF endings in the same event block
                $onChunk("data: {\"type\": \"text\",\r\ndata:  \"content\": \"hello\"}\r\n\r\n");
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        // With correct CRLF normalization, this produces exactly one event
        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['type']);
        $this->assertSame('hello', $events[0]['content']);
    }

    public function testStreamSseCommentLinesBetweenDataLines(): void
    {
        // Comments between data lines should be skipped, not break the event
        $sseData = ": comment 1\n"
            . "data: {\"type\": \"text\", \"content\": \"first\"}\n\n"
            . ": comment 2\n"
            . ": comment 3\n\n"
            . "data: {\"type\": \"text\", \"content\": \"second\"}\n\n";

        $transport = $this->createStreamingTransport($sseData);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $events = [];
        $client->streamSse('/test', ['d' => 1], function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['content']);
        $this->assertSame('second', $events[1]['content']);
    }
}

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
     * Verifies streamSse() sends the correct URL, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() sends the expected payload, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() applies authentication, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() uses the configured timeout by default, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() uses per-request timeout, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() cancels when the callback returns false, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() continues when the callback returns void, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() honors cancellation across network chunks, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() propagates transport error, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() throws on encoding failure, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() rejects zero timeout, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() rejects negative timeout, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() accepts the one-second timeout boundary, keeping raw SSE callbacks predictable for calling applications.
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
     * Verifies streamSse() logs request and completion context, keeping raw SSE callbacks predictable for calling applications.
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

}

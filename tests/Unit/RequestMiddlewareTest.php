<?php

declare(strict_types=1);

/**
 * Tests caller-visible Request Middleware behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamResult;

/**
 * Verifies Request Middleware behavior that application users rely on.
 */
class RequestMiddlewareTest extends TestCase
{
    /**
     * Supports the load fixture step in the app-facing flow.
     *
     * @param string $name Fixture or attachment name used in the test flow.
     * @return array<string, mixed> Fixture payload used to simulate an agent response.
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/' . $name;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Fixture not found: $path");
        }

        /** @var array<string, mixed> $data validated before app code uses it. */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Verifies that middleware before request called on invoke.
     *
     * @return void
     */
    public function testMiddlewareBeforeRequestCalledOnInvoke(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->with(
                'http://localhost:8081/invoke',
                $this->isType('array'),
                $this->isType('string'),
            )
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => array_merge($headers, ['X-Trace-Id' => 'abc-123']),
                'body' => $body,
            ]);

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $h) => ($h['X-Trace-Id'] ?? null) === 'abc-123'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies that middleware after response called on success.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnSuccess(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/invoke',
                200,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies that middleware after response called onError.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnError(): void
    {
        $agentErrorException = new AgentErrorException('Bad request', statusCode: 400);

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/invoke',
                400,
                $this->greaterThan(0),
                $this->identicalTo($agentErrorException),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willThrowException($agentErrorException);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Bad request');
        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that middleware after response runs when request setup fails after beforeRequest.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledWhenRequestSetupFailsAfterBeforeRequest(): void
    {
        $setupException = new \RuntimeException('auth failed');

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/invoke',
                0,
                $this->greaterThanOrEqual(0),
                $this->identicalTo($setupException),
            );

        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->once())
            ->method('authenticate')
            ->willThrowException($setupException);

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', auth: $auth),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectExceptionObject($setupException);
        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that setup failures before middleware starts do not send after response.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseNotCalledWhenEncodingFailsBeforeBeforeRequest(): void
    {
        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->never())->method('beforeRequest');
        $middleware->expects($this->never())->method('afterResponse');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(StrandsException::class);
        $this->expectExceptionMessage('Failed to encode request payload');

        $strandsClient->postJson('/file-summarise', ['bad_value' => NAN]);
    }

    /**
     * Verifies that middleware after response exception is logged.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseExceptionIsLogged(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->any())->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->method('afterResponse')
            ->willThrowException(new \RuntimeException('middleware broke'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Middleware afterResponse threw an exception',
                $this->callback(fn (array $context) => is_string($context['error'] ?? null) && str_contains($context['error'], 'middleware broke')),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
            middleware: [$middleware],
        );

        // Should NOT throw — middleware exceptions are caught
        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies that multiple middleware executed in order.
     *
     * @return void
     */
    public function testMultipleMiddlewareExecutedInOrder(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $callOrder = [];

        $mw1 = $this->createMock(RequestMiddleware::class);
        $mw1->expects($this->any())->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw1:before';

                return ['headers' => array_merge($headers, ['X-First' => '1']), 'body' => $body];
            });
        $mw1->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw1:after';
            });

        $mw2 = $this->createMock(RequestMiddleware::class);
        $mw2->expects($this->any())->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw2:before';

                return ['headers' => array_merge($headers, ['X-Second' => '2']), 'body' => $body];
            });
        $mw2->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw2:after';
            });

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $h) => ($h['X-First'] ?? null) === '1' && ($h['X-Second'] ?? null) === '2'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$mw1, $mw2],
        );

        $strandsClient->invoke(message: 'Test');

        $this->assertSame(['mw1:before', 'mw2:before', 'mw1:after', 'mw2:after'], $callOrder);
    }

    /**
     * Verifies that middleware called on stream.
     *
     * @return void
     */
    public function testMiddlewareCalledOnStream(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->with(
                'http://localhost:8081/stream',
                $this->isType('array'),
                $this->isType('string'),
            )
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/stream',
                200,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies that middleware called on stream error.
     *
     * @return void
     */
    public function testMiddlewareCalledOnStreamError(): void
    {
        $agentErrorException = new AgentErrorException('Server error', statusCode: 500);

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/stream',
                500,
                $this->greaterThan(0),
                $this->identicalTo($agentErrorException),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willThrowException($agentErrorException);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Server error');
        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies that middleware called on stream interrupted.
     *
     * @return void
     */
    public function testMiddlewareCalledOnStreamInterrupted(): void
    {
        // Stream with no terminal event
        $sseData = "data: {\"type\": \"text\", \"content\": \"partial\"}\n\n";

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->anything(),
                0,
                $this->greaterThan(0),
                $this->isInstanceOf(\StrandsPhpClient\Exceptions\StreamInterruptedException::class),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(\StrandsPhpClient\Exceptions\StreamInterruptedException::class);
        $this->expectExceptionMessageMatches('/ended without a terminal event/');
        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies that middleware applied to post JSON.
     *
     * @return void
     */
    public function testMiddlewareAppliedToPostJson(): void
    {
        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) {
                return [
                    'headers' => array_merge($headers, ['X-Custom' => 'traced']),
                    'body' => $body,
                ];
            });

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $h) => ($h['X-Custom'] ?? null) === 'traced'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['result' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $result = $strandsClient->postJson('/custom', ['key' => 'value']);

        $this->assertSame(['result' => 'ok'], $result);
    }

    /**
     * Verifies that middleware after response called on post JSON success.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnPostJsonSuccess(): void
    {
        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->stringContains('/custom'),
                200,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn(['result' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $result = $strandsClient->postJson('/custom', ['key' => 'value']);

        $this->assertSame(['result' => 'ok'], $result);
    }

    /**
     * Verifies that middleware after response called on post JSON error.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnPostJsonError(): void
    {
        $agentErrorException = new AgentErrorException('Not found', statusCode: 404);

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->stringContains('/custom'),
                404,
                $this->greaterThan(0),
                $this->identicalTo($agentErrorException),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willThrowException($agentErrorException);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Not found');
        $strandsClient->postJson('/custom', ['key' => 'value']);
    }

    /**
     * Verifies that middleware after response called on stream SSE success.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnStreamSseSuccess(): void
    {
        $sseData = "data: {\"status\": \"ok\"}\n\n";

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->stringContains('/custom-stream'),
                200,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $eventCount = 0;
        $strandsClient->streamSse('/custom-stream', ['key' => 'value'], function () use (&$eventCount): void {
            $eventCount++;
        });

        $this->assertSame(1, $eventCount, 'onEvent must receive each parsed SSE event');
    }

    /**
     * Verifies that middleware after response called on stream SSE error.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnStreamSseError(): void
    {
        $agentErrorException = new AgentErrorException('Server error', statusCode: 500);

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->stringContains('/custom-stream'),
                500,
                $this->greaterThan(0),
                $this->identicalTo($agentErrorException),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')->willThrowException($agentErrorException);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Server error');
        $strandsClient->streamSse('/custom-stream', ['key' => 'value'], function (): void {
        });
    }

    /**
     * Verifies that middleware after response called on stream SSE cancelled.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseCalledOnStreamSseCancelled(): void
    {
        $sseData = "data: {\"status\": \"partial\"}\n\n";

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->stringContains('/custom-stream'),
                0,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $cancelCalls = 0;
        $strandsClient->streamSse('/custom-stream', ['key' => 'value'], function () use (&$cancelCalls): bool {
            $cancelCalls++;

            return false;
        });

        $this->assertSame(1, $cancelCalls, 'onEvent must run once before returning false cancels the stream');
    }

    /**
     * Verifies that a middleware whose beforeRequest threw still receives afterResponse.
     *
     * @return void
     */
    public function testSetupFailureNotifiesMiddlewareThatThrewInBeforeRequest(): void
    {
        $setupException = new \RuntimeException('middleware exploded');

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->expects($this->once())
            ->method('beforeRequest')
            ->willThrowException($setupException);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                'http://localhost:8081/invoke',
                0,
                $this->greaterThanOrEqual(0),
                $this->identicalTo($setupException),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectExceptionObject($setupException);
        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that middleware never entered before a setup failure gets no afterResponse.
     *
     * @return void
     */
    public function testSetupFailureSkipsMiddlewareNeverEntered(): void
    {
        $setupException = new \RuntimeException('second middleware exploded');
        $callOrder = [];

        $enteredMiddleware = $this->createMock(RequestMiddleware::class);
        $enteredMiddleware->expects($this->once())->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw1:before';

                return ['headers' => $headers, 'body' => $body];
            });
        $enteredMiddleware->expects($this->once())->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw1:after';
            });

        $throwingMiddleware = $this->createMock(RequestMiddleware::class);
        $throwingMiddleware->expects($this->once())->method('beforeRequest')
            ->willReturnCallback(function () use (&$callOrder, $setupException) {
                $callOrder[] = 'mw2:before';

                throw $setupException;
            });
        $throwingMiddleware->expects($this->once())->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw2:after';
            });

        $unreachedMiddleware = $this->createMock(RequestMiddleware::class);
        $unreachedMiddleware->expects($this->never())->method('beforeRequest');
        $unreachedMiddleware->expects($this->never())->method('afterResponse');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$enteredMiddleware, $throwingMiddleware, $unreachedMiddleware],
        );

        // The failure must still reach the caller after teardown, so catch it here to assert ordering afterwards.
        try {
            $strandsClient->invoke(message: 'Test');
            $this->fail('invoke() must rethrow the middleware setup failure');
        } catch (\RuntimeException $caught) {
            $this->assertSame($setupException, $caught);
        }

        $this->assertSame(['mw1:before', 'mw2:before', 'mw1:after', 'mw2:after'], $callOrder);
    }

    /**
     * Verifies that cancelled stream reports status zero.
     *
     * @return void
     */
    public function testCancelledStreamReportsStatusZero(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hi\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hi\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";

        $middleware = $this->createMock(RequestMiddleware::class);
        $middleware->method('beforeRequest')
            ->willReturnCallback(fn (string $url, array $headers, string $body) => [
                'headers' => $headers,
                'body' => $body,
            ]);
        $middleware->expects($this->once())
            ->method('afterResponse')
            ->with(
                $this->anything(),
                0,
                $this->greaterThan(0),
                null,
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        // Cancel on first event
        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: fn () => false,
        );

        $this->assertTrue($streamResult->cancelled, 'Returning false from onEvent must mark the stream as cancelled');
    }
}

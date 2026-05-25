<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\StrandsClient;

class RequestMiddlewareTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/' . $name;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Fixture not found: $path");
        }

        /** @var array<string, mixed> $data */
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

        $strandsClient->invoke(message: 'Test');
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $strandsClient->invoke(message: 'Test');
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willThrowException($agentErrorException);

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
     * Verifies that middleware after response exception is logged.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseExceptionIsLogged(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $middleware = $this->createStub(RequestMiddleware::class);
        $middleware->method('beforeRequest')
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
            middleware: [$middleware],
        );

        // Should NOT throw — middleware exceptions are caught
        $strandsClient->invoke(message: 'Test');
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

        $mw1 = $this->createStub(RequestMiddleware::class);
        $mw1->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw1:before';

                return ['headers' => array_merge($headers, ['X-First' => '1']), 'body' => $body];
            });
        $mw1->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw1:after';
            });

        $mw2 = $this->createStub(RequestMiddleware::class);
        $mw2->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw2:before';

                return ['headers' => array_merge($headers, ['X-Second' => '2']), 'body' => $body];
            });
        $mw2->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw2:after';
            });

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
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

        $strandsClient->postJson('/custom', ['key' => 'value']);
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(['result' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $strandsClient->postJson('/custom', ['key' => 'value']);
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willThrowException($agentErrorException);

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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $strandsClient->streamSse('/custom-stream', ['key' => 'value'], function (): void {
        });
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')->willThrowException($agentErrorException);

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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $strandsClient->streamSse('/custom-stream', ['key' => 'value'], fn () => false);
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

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        // Cancel on first event
        $strandsClient->stream(
            message: 'Test',
            onEvent: fn () => false,
        );
    }
}

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->invoke(message: 'Test');
    }

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
        $transport->method('post')->willReturn($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->invoke(message: 'Test');
    }

    public function testMiddlewareAfterResponseCalledOnError(): void
    {
        $error = new AgentErrorException('Bad request', statusCode: 400);

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
                $this->identicalTo($error),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')->willThrowException($error);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $client->invoke(message: 'Test');
    }

    public function testMiddlewareAfterResponseExceptionIsLogged(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $middleware = $this->createMock(RequestMiddleware::class);
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
                $this->callback(fn (array $ctx) => is_string($ctx['error'] ?? null) && str_contains($ctx['error'], 'middleware broke')),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')->willReturn($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
            middleware: [$middleware],
        );

        // Should NOT throw — middleware exceptions are caught
        $client->invoke(message: 'Test');
    }

    public function testMultipleMiddlewareExecutedInOrder(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $callOrder = [];

        $mw1 = $this->createMock(RequestMiddleware::class);
        $mw1->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw1:before';

                return ['headers' => array_merge($headers, ['X-First' => '1']), 'body' => $body];
            });
        $mw1->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw1:after';
            });

        $mw2 = $this->createMock(RequestMiddleware::class);
        $mw2->method('beforeRequest')
            ->willReturnCallback(function (string $url, array $headers, string $body) use (&$callOrder) {
                $callOrder[] = 'mw2:before';

                return ['headers' => array_merge($headers, ['X-Second' => '2']), 'body' => $body];
            });
        $mw2->method('afterResponse')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'mw2:after';
            });

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $h) => ($h['X-First'] ?? null) === '1' && ($h['X-Second'] ?? null) === '2'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$mw1, $mw2],
        );

        $client->invoke(message: 'Test');

        $this->assertSame(['mw1:before', 'mw2:before', 'mw1:after', 'mw2:after'], $callOrder);
    }

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
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    public function testMiddlewareCalledOnStreamError(): void
    {
        $error = new AgentErrorException('Server error', statusCode: 500);

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
                $this->identicalTo($error),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willThrowException($error);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

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
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(\StrandsPhpClient\Exceptions\StreamInterruptedException::class);
        $client->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->postJson('/custom', ['key' => 'value']);
    }

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
        $transport->method('post')->willReturn(['result' => 'ok']);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->postJson('/custom', ['key' => 'value']);
    }

    public function testMiddlewareAfterResponseCalledOnPostJsonError(): void
    {
        $error = new AgentErrorException('Not found', statusCode: 404);

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
                $this->identicalTo($error),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')->willThrowException($error);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $client->postJson('/custom', ['key' => 'value']);
    }

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
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->streamSse('/custom-stream', ['key' => 'value'], function (): void {
        });
    }

    public function testMiddlewareAfterResponseCalledOnStreamSseError(): void
    {
        $error = new AgentErrorException('Server error', statusCode: 500);

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
                $this->identicalTo($error),
            );

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')->willThrowException($error);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $this->expectException(AgentErrorException::class);
        $client->streamSse('/custom-stream', ['key' => 'value'], function (): void {
        });
    }

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
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        $client->streamSse('/custom-stream', ['key' => 'value'], fn () => false);
    }

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
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            middleware: [$middleware],
        );

        // Cancel on first event
        $client->stream(
            message: 'Test',
            onEvent: fn () => false,
        );
    }
}

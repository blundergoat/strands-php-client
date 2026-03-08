<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\PsrHttpTransport;

class PsrHttpTransportTest extends TestCase
{
    private function createTransport(
        ResponseInterface $response,
    ): PsrHttpTransport {
        $stream = $this->createMock(StreamInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        return new PsrHttpTransport($httpClient, $requestFactory, $streamFactory);
    }

    private function createResponse(int $statusCode, string $body): ResponseInterface
    {
        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($bodyStream);

        return $response;
    }

    public function testPostReturnsDecodedJson(): void
    {
        $response = $this->createResponse(200, '{"text":"hello","session_id":"s1"}');
        $transport = $this->createTransport($response);

        $result = $transport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('hello', $result['text']);
        $this->assertSame('s1', $result['session_id']);
    }

    public function testPostThrowsAgentErrorOnHttpError(): void
    {
        $response = $this->createResponse(422, '{"detail":"Something went wrong"}');
        $transport = $this->createTransport($response);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Something went wrong');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsAgentErrorWithErrorKey(): void
    {
        $response = $this->createResponse(400, '{"error":"Bad request"}');
        $transport = $this->createTransport($response);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Bad request');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsAgentErrorWithPlainTextBody(): void
    {
        $response = $this->createResponse(500, 'Internal Server Error');
        $transport = $this->createTransport($response);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsStrandsExceptionOnInvalidJson(): void
    {
        $response = $this->createResponse(200, 'not json at all');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $e) {
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $e->getMessage());
        }
    }

    public function testPostWrapsClientException(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $transport = new PsrHttpTransport($httpClient, $requestFactory, $streamFactory);

        $this->expectException(StrandsException::class);
        $this->expectExceptionMessage('HTTP request to agent failed: Connection refused');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostSendsHeaders(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->exactly(2))
            ->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($request) {
                $this->assertContains($name, ['Content-Type', 'Accept']);

                return $request;
            });
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createResponse(200, '{"text":"ok"}');
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $transport = new PsrHttpTransport($httpClient, $requestFactory, $streamFactory);

        $transport->post(
            'http://example.com/invoke',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{}',
            30,
            10,
        );
    }

    public function testStreamThrowsStrandsException(): void
    {
        $response = $this->createResponse(200, '{}');
        $transport = $this->createTransport($response);

        try {
            $transport->stream('http://example.com/stream', [], '{}', 30, 10, function () {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertStringContainsString('SSE streaming is not supported', $e->getMessage());
            $this->assertStringContainsString('PsrHttpTransport', $e->getMessage());
            $this->assertStringContainsString('symfony/http-client', $e->getMessage());
            $this->assertStringContainsString('SymfonyHttpTransport', $e->getMessage());
        }
    }

    public function testTimeoutWarningLoggedOnceWithContext(): void
    {
        $response = $this->createResponse(200, '{"text":"ok"}');

        $stream = $this->createMock(StreamInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with(
                $this->callback(function (string $message): bool {
                    return str_contains($message, 'does not support timeout')
                        && str_contains($message, 'Configure timeout')
                        && str_contains($message, 'PSR-18 client');
                }),
                $this->callback(function (array $context): bool {
                    return isset($context['timeout'])
                        && isset($context['connectTimeout'])
                        && $context['timeout'] === 30
                        && $context['connectTimeout'] === 10;
                }),
            );

        $transport = new PsrHttpTransport($httpClient, $requestFactory, $streamFactory, $logger);

        // First call should log
        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
        // Second call should NOT log again (once-only)
        $transport->post('http://example.com/invoke', [], '{}', 60, 20);
    }

    public function testPostErrorPrefersDetailOverError(): void
    {
        $response = $this->createResponse(422, '{"detail":"Specific detail","error":"General error"}');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('Agent returned HTTP 422: Specific detail', $e->getMessage());
            $this->assertSame(422, $e->statusCode);
        }
    }

    public function testPostErrorHandlesArrayDetail(): void
    {
        $response = $this->createResponse(422, '{"detail":["Error 1","Error 2"]}');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('Agent returned HTTP 422: ["Error 1","Error 2"]', $e->getMessage());
        }
    }

    public function testPostErrorFallsBackToContentWhenNoDetailOrError(): void
    {
        $response = $this->createResponse(500, '{"some_key":"value"}');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $e->getMessage());
        }
    }

    public function testPostDoesNotThrowOn399StatusCode(): void
    {
        $response = $this->createResponse(399, '{"text":"ok"}');
        $transport = $this->createTransport($response);

        $result = $transport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('ok', $result['text']);
    }

    public function testPostErrorIncludesResponseBody(): void
    {
        $response = $this->createResponse(422, '{"detail":"Validation failed","errors":[{"field":"name","msg":"required"}]}');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertIsArray($e->responseBody);
            $this->assertSame('Validation failed', $e->responseBody['detail']);
            $this->assertCount(1, $e->responseBody['errors']);
        }
    }

    public function testPostErrorResponseBodyNullForPlainText(): void
    {
        $response = $this->createResponse(500, 'Internal Server Error');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertNull($e->responseBody);
        }
    }

    public function testPostDoesNotDoubleWrapStrandsException(): void
    {
        $response = $this->createResponse(200, 'not json');
        $transport = $this->createTransport($response);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $e->getMessage());
            $this->assertStringNotContainsString('HTTP request to agent failed', $e->getMessage());
        }
    }
}

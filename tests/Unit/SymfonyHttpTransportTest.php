<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\SymfonyHttpTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class SymfonyHttpTransportTest extends TestCase
{
    /**
     * @param list<ChunkInterface> $chunks
     */
    private function createTransportWithStreamChunks(array $chunks, int $statusCode = 200, string $body = ''): SymfonyHttpTransport
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn($body);

        $stream = new class ($response, $chunks) implements ResponseStreamInterface {
            /**
             * @param list<ChunkInterface> $chunks
             */
            public function __construct(
                private readonly ResponseInterface $response,
                private readonly array $chunks,
                private int $position = 0,
            ) {
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function current(): ChunkInterface
            {
                return $this->chunks[$this->position];
            }

            public function key(): ResponseInterface
            {
                return $this->response;
            }

            public function next(): void
            {
                ++$this->position;
            }

            public function valid(): bool
            {
                return isset($this->chunks[$this->position]);
            }
        };

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        return new SymfonyHttpTransport($httpClient);
    }

    private function createChunk(bool $timeout, bool $last, string $content = ''): ChunkInterface
    {
        $chunk = $this->createMock(ChunkInterface::class);
        $chunk->method('isTimeout')->willReturn($timeout);
        $chunk->method('isLast')->willReturn($last);
        $chunk->method('getContent')->willReturn($content);
        $chunk->method('isFirst')->willReturn(false);
        $chunk->method('getInformationalStatus')->willReturn(null);
        $chunk->method('getOffset')->willReturn(0);
        $chunk->method('getError')->willReturn(null);

        return $chunk;
    }

    public function testPostReturnsDecodedJson(): void
    {
        $mockResponse = new MockResponse('{"text":"hello","session_id":"s1"}', [
            'http_code' => 200,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $result = $transport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('hello', $result['text']);
        $this->assertSame('s1', $result['session_id']);
    }

    public function testPostThrowsAgentErrorOnHttpError(): void
    {
        $mockResponse = new MockResponse('{"detail":"Something went wrong"}', [
            'http_code' => 422,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Something went wrong');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsAgentErrorWithErrorKey(): void
    {
        $mockResponse = new MockResponse('{"error":"Bad request"}', [
            'http_code' => 400,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Bad request');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsAgentErrorWithPlainTextBody(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', [
            'http_code' => 500,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $transport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    public function testPostThrowsStrandsExceptionOnInvalidJson(): void
    {
        $mockResponse = new MockResponse('not json at all', [
            'http_code' => 200,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $e) {
            // Assert exact message - must NOT be double-wrapped with "HTTP request to agent failed:" prefix
            $this->assertSame('Invalid JSON response from agent', $e->getMessage());
        }
    }

    public function testStreamDeliversChunks(): void
    {
        $body = "data: {\"type\":\"text\",\"content\":\"hi\"}\n\n";
        $mockResponse = new MockResponse($body, [
            'http_code' => 200,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $chunks = [];
        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('text', implode('', $chunks));
    }

    public function testStreamThrowsAgentErrorOnHttpError(): void
    {
        $mockResponse = new MockResponse('Server error', [
            'http_code' => 500,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $this->expectException(AgentErrorException::class);

        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function () {
        });
    }

    public function testStreamThrowsInterruptedExceptionOnTimeoutChunk(): void
    {
        $transport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: true, last: false),
        ]);

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('Stream timed out');

        $transport->stream('http://example.com/stream', [], '{}', 1, 10, function () {
        });
    }

    public function testStreamStopsOnLastChunkWithoutPublishingContent(): void
    {
        $transport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: true),
        ]);

        $received = [];
        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertSame([], $received);
    }

    public function testStreamDeliversContentFromLastChunk(): void
    {
        $transport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: false, content: 'data: {"type":"text","content":"hello"}\n\n'),
            $this->createChunk(timeout: false, last: true, content: 'data: {"type":"complete","text":"hello"}\n\n'),
        ]);

        $received = [];
        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertCount(2, $received);
        $this->assertStringContainsString('complete', $received[1]);
    }

    public function testExceptionClasses(): void
    {
        $strands = new StrandsException('base error');
        $this->assertSame('base error', $strands->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $strands);

        $agent = new AgentErrorException('agent error', 422, 'ERR_001');
        $this->assertSame(422, $agent->statusCode);
        $this->assertSame('ERR_001', $agent->errorCode);
        $this->assertSame('agent error', $agent->getMessage());

        $interrupted = new StreamInterruptedException('stream dropped');
        $this->assertSame('stream dropped', $interrupted->getMessage());
        $this->assertInstanceOf(StrandsException::class, $interrupted);
    }

    public function testAgentErrorExceptionDefaultStatusCode(): void
    {
        $e = new AgentErrorException('error');
        $this->assertSame(0, $e->statusCode);
        $this->assertNull($e->errorCode);
    }

    public function testPostWrapsNonStrandsException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('DNS resolution failed'));

        $transport = new SymfonyHttpTransport($httpClient);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertSame('HTTP request to agent failed: DNS resolution failed', $e->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testStreamWrapsNonStrandsException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection reset'));

        $transport = new SymfonyHttpTransport($httpClient);

        try {
            $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $e) {
            $this->assertSame('Streaming request to agent failed: Connection reset', $e->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testPostErrorPrefersDetailOverError(): void
    {
        $mockResponse = new MockResponse('{"detail":"Specific detail","error":"General error"}', [
            'http_code' => 422,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('Specific detail', $e->getMessage());
            $this->assertSame(422, $e->statusCode);
        }
    }

    public function testPostErrorHandlesArrayDetail(): void
    {
        $mockResponse = new MockResponse('{"detail":["Error 1","Error 2"]}', [
            'http_code' => 422,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('["Error 1","Error 2"]', $e->getMessage());
        }
    }

    public function testPostErrorFallsBackToContentWhenNoDetailOrError(): void
    {
        $mockResponse = new MockResponse('{"some_key":"value"}', [
            'http_code' => 500,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('{"some_key":"value"}', $e->getMessage());
        }
    }

    public function testStreamThrowsOn400StatusCode(): void
    {
        $mockResponse = new MockResponse('Bad Request', [
            'http_code' => 400,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('Bad Request', $e->getMessage());
        }
    }

    public function testPostDoesNotThrowOn399StatusCode(): void
    {
        $mockResponse = new MockResponse('{"text":"ok"}', [
            'http_code' => 399,
        ]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        $result = $transport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('ok', $result['text']);
    }

    public function testPostSendsHeadersToSymfony(): void
    {
        $capturedOptions = [];
        $mockResponse = new MockResponse('{"text":"ok"}', ['http_code' => 200]);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions, $mockResponse) {
            $capturedOptions = $options;

            return $mockResponse;
        });
        $transport = new SymfonyHttpTransport($client);

        $transport->post('http://example.com/invoke', ['X-Custom' => 'test-value'], '{}', 30, 10);

        // Symfony normalizes headers to an indexed array of "name: value" strings
        $this->assertContains('X-Custom: test-value', $capturedOptions['headers']);
    }

    public function testPostSendsBodyToSymfony(): void
    {
        $capturedOptions = [];
        $mockResponse = new MockResponse('{"text":"ok"}', ['http_code' => 200]);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions, $mockResponse) {
            $capturedOptions = $options;

            return $mockResponse;
        });
        $transport = new SymfonyHttpTransport($client);

        $transport->post('http://example.com/invoke', [], '{"message":"hi"}', 30, 10);

        $this->assertSame('{"message":"hi"}', $capturedOptions['body']);
    }

    public function testPostSendsTimeoutToSymfony(): void
    {
        $capturedOptions = [];
        $mockResponse = new MockResponse('{"text":"ok"}', ['http_code' => 200]);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions, $mockResponse) {
            $capturedOptions = $options;

            return $mockResponse;
        });
        $transport = new SymfonyHttpTransport($client);

        $transport->post('http://example.com/invoke', [], '{}', 45, 5);

        $this->assertEquals(5, $capturedOptions['timeout']);
        $this->assertEquals(45, $capturedOptions['max_duration']);
    }
}

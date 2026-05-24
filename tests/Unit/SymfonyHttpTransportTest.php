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
    private function createResponseStream(ResponseInterface $response, array $chunks): ResponseStreamInterface
    {
        return new class ($response, $chunks) implements ResponseStreamInterface {
            /**
             * @param list<ChunkInterface> $chunks
             */
            public function __construct(
                private readonly ResponseInterface $response,
                private readonly array $chunks,
                private int $position = 0,
            ) {
            }

            /**
             * Reset the mock response stream iterator.
             *
             * @return void
             */
            public function rewind(): void
            {
                $this->position = 0;
            }

            /**
             * Return the current mock response stream chunk.
             *
             * @return ChunkInterface Current mock stream chunk.
             */
            public function current(): ChunkInterface
            {
                return $this->chunks[$this->position];
            }

            /**
             * Return the response associated with the current mock stream chunk.
             *
             * @return ResponseInterface Response associated with the current mock stream
             * chunk.
             */
            public function key(): ResponseInterface
            {
                return $this->response;
            }

            /**
             * Advance the mock response stream iterator.
             *
             * @return void
             */
            public function next(): void
            {
                ++$this->position;
            }

            /**
             * Determine whether the mock response stream iterator has a current chunk.
             *
             * @return bool True when the iterator points at a mock chunk.
             */
            public function valid(): bool
            {
                return isset($this->chunks[$this->position]);
            }
        };
    }

    /**
     * @param list<ChunkInterface> $chunks
     */
    private function createTransportWithStreamChunks(array $chunks, int $statusCode = 200, string $body = ''): SymfonyHttpTransport
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn($body);

        $stream = $this->createResponseStream($response, $chunks);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        return new SymfonyHttpTransport($httpClient);
    }

    /**
     * Create chunk for the test scenario.
     *
     * @param bool $timeout Whether the mock chunk should simulate a timeout.
     * @param bool $last Whether the mock chunk should simulate the last chunk.
     * @param string $content Chunk content yielded by the mock response.
     * @return ChunkInterface Value produced by the method.
     */
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

    /**
     * Verifies that post returns decoded JSON.
     *
     * @return void
     */
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

    /**
     * Verifies that post throws agent error on HTTP error.
     *
     * @return void
     */
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

    /**
     * Verifies that post throws agent error with error key.
     *
     * @return void
     */
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

    /**
     * Verifies that post throws agent error with plain text body.
     *
     * @return void
     */
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

    /**
     * Verifies that post throws strands exception on invalid JSON.
     *
     * @return void
     */
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
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $e->getMessage());
        }
    }

    /**
     * Verifies that stream delivers chunks.
     *
     * @return void
     */
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

    /**
     * Verifies that stream throws agent error on HTTP error.
     *
     * @return void
     */
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

    /**
     * Verifies that stream throws interrupted exception on timeout chunk.
     *
     * @return void
     */
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

    /**
     * Verifies that stream stops on last chunk without publishing content.
     *
     * @return void
     */
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

    /**
     * Verifies that stream delivers content from last chunk.
     *
     * @return void
     */
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

    /**
     * Verifies that stream stops on callback return false.
     *
     * @return void
     */
    public function testStreamStopsOnCallbackReturnFalse(): void
    {
        $transport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: false, content: 'chunk1'),
            $this->createChunk(timeout: false, last: false, content: 'chunk2'),
            $this->createChunk(timeout: false, last: true, content: 'chunk3'),
        ]);

        $received = [];
        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received): bool {
            $received[] = $chunk;

            return false;  // cancel after first chunk
        });

        $this->assertCount(1, $received);
        $this->assertSame('chunk1', $received[0]);
    }

    /**
     * Verifies that stream cancels response on callback return false.
     *
     * @return void
     */
    public function testStreamCancelsResponseOnCallbackReturnFalse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('cancel');

        $chunk = $this->createChunk(timeout: false, last: false, content: 'data');
        $stream = $this->createResponseStream($response, [$chunk]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $transport = new SymfonyHttpTransport($httpClient);

        $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (): bool {
            return false;
        });
    }

    /**
     * Verifies that exception classes.
     *
     * @return void
     */
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

    /**
     * Verifies that agent error exception default status code.
     *
     * @return void
     */
    public function testAgentErrorExceptionDefaultStatusCode(): void
    {
        $e = new AgentErrorException('error');
        $this->assertSame(0, $e->statusCode);
        $this->assertNull($e->errorCode);
        $this->assertNull($e->responseBody);
    }

    /**
     * Verifies that agent error exception carries response body.
     *
     * @return void
     */
    public function testAgentErrorExceptionCarriesResponseBody(): void
    {
        $e = new AgentErrorException('error', 422, responseBody: ['detail' => 'bad', 'fields' => ['name' => 'required']]);
        $this->assertSame(422, $e->statusCode);
        $this->assertSame(['detail' => 'bad', 'fields' => ['name' => 'required']], $e->responseBody);
    }

    /**
     * Verifies that post error includes response body.
     *
     * @return void
     */
    public function testPostErrorIncludesResponseBody(): void
    {
        $body = '{"detail":"Validation failed","errors":[{"field":"name","msg":"required"}]}';
        $mockResponse = new MockResponse($body, ['http_code' => 422]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

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

    /**
     * Verifies that post error response body null for plain text.
     *
     * @return void
     */
    public function testPostErrorResponseBodyNullForPlainText(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', ['http_code' => 500]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertNull($e->responseBody);
        }
    }

    /**
     * Verifies that stream error includes response body.
     *
     * @return void
     */
    public function testStreamErrorIncludesResponseBody(): void
    {
        $body = '{"detail":"Stream error","code":"RATE_LIMIT"}';
        $mockResponse = new MockResponse($body, ['http_code' => 429]);
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertIsArray($e->responseBody);
            $this->assertSame('RATE_LIMIT', $e->responseBody['code']);
        }
    }

    /**
     * Verifies that post wraps non strands exception.
     *
     * @return void
     */
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

    /**
     * Verifies that stream wraps non strands exception.
     *
     * @return void
     */
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

    /**
     * Verifies that post error prefers detail over error.
     *
     * @return void
     */
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
            $this->assertSame('Agent returned HTTP 422: Specific detail', $e->getMessage());
            $this->assertSame(422, $e->statusCode);
        }
    }

    /**
     * Verifies that post error handles array detail.
     *
     * @return void
     */
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
            $this->assertSame('Agent returned HTTP 422: ["Error 1","Error 2"]', $e->getMessage());
        }
    }

    /**
     * Verifies that post error falls back to content when no detail or error.
     *
     * @return void
     */
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
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $e->getMessage());
        }
    }

    /**
     * Verifies that stream throws on 400 status code.
     *
     * @return void
     */
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
            $this->assertSame('Agent returned HTTP 400: Bad Request', $e->getMessage());
        }
    }

    /**
     * Verifies that post does not throw on 399 status code.
     *
     * @return void
     */
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

    /**
     * Verifies that post sends headers to symfony.
     *
     * @return void
     */
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

    /**
     * Verifies that post sends body to symfony.
     *
     * @return void
     */
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

    /**
     * Verifies that post sends timeout to symfony.
     *
     * @return void
     */
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

    /**
     * Verifies that post error extracts error code.
     *
     * @return void
     */
    public function testPostErrorExtractsErrorCode(): void
    {
        $mockResponse = new MockResponse(
            '{"detail":"Unauthorized","code":"auth_failed"}',
            ['http_code' => 401],
        );
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('auth_failed', $e->errorCode);
        }
    }

    /**
     * Verifies that post error extracts error code from alternate key.
     *
     * @return void
     */
    public function testPostErrorExtractsErrorCodeFromAlternateKey(): void
    {
        $mockResponse = new MockResponse(
            '{"detail":"Rate limited","error_code":"throttled"}',
            ['http_code' => 429],
        );
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('throttled', $e->errorCode);
        }
    }

    /**
     * Verifies that post error code is null when absent.
     *
     * @return void
     */
    public function testPostErrorCodeIsNullWhenAbsent(): void
    {
        $mockResponse = new MockResponse(
            '{"detail":"Server error"}',
            ['http_code' => 500],
        );
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertNull($e->errorCode);
        }
    }

    /**
     * Verifies that post error code prefers code over error code.
     *
     * @return void
     */
    public function testPostErrorCodePrefersCodeOverErrorCode(): void
    {
        $mockResponse = new MockResponse(
            '{"detail":"Error","code":"primary_code","error_code":"fallback_code"}',
            ['http_code' => 400],
        );
        $client = new MockHttpClient($mockResponse);
        $transport = new SymfonyHttpTransport($client);

        try {
            $transport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame('primary_code', $e->errorCode);
        }
    }
}

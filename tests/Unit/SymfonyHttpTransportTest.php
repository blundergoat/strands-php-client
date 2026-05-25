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
     * Build a SymfonyHttpTransport whose mock client returns the given response body and status.
     * Centralises the MockResponse + MockHttpClient + transport wiring so per-test bodies
     * stay short and read as setup-then-act rather than transport-construction boilerplate.
     *
     * @param string $responseBody Body returned by the underlying mock HTTP response.
     * @param int $statusCode HTTP status code for the mocked response.
     * @return SymfonyHttpTransport Configured transport ready for a post() / stream() call.
     */
    private function transportReturning(string $responseBody, int $statusCode = 200): SymfonyHttpTransport
    {
        $mockResponse = new MockResponse($responseBody, ['http_code' => $statusCode]);

        return new SymfonyHttpTransport(new MockHttpClient($mockResponse));
    }

    /**
     * Build a SymfonyHttpTransport whose mock client captures the Symfony HTTP
     * options array on every call. Useful for tests asserting on headers, body,
     * or timeout values forwarded by SymfonyHttpTransport without spelling out
     * the closure-mock plumbing in each test body.
     *
     * @param-out array<string, mixed> $capturedOptions Reference filled with the options array passed to the mock client.
     * @return SymfonyHttpTransport Configured transport.
     */
    private function transportCapturingOptions(array &$capturedOptions): SymfonyHttpTransport
    {
        $mockResponse = new MockResponse('{"text":"ok"}', ['http_code' => 200]);
        $mockHttpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions, $mockResponse): MockResponse {
            $capturedOptions = $options;

            return $mockResponse;
        });

        return new SymfonyHttpTransport($mockHttpClient);
    }

    /**
     * Verifies that post returns decoded JSON.
     *
     * @return void
     */
    public function testPostReturnsDecodedJson(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"text":"hello","session_id":"s1"}');

        $result = $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

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
        $symfonyHttpTransport = $this->transportReturning('{"detail":"Something went wrong"}', 422);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Something went wrong');

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    /**
     * Verifies that post throws agent error with error key.
     *
     * @return void
     */
    public function testPostThrowsAgentErrorWithErrorKey(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"error":"Bad request"}', 400);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Bad request');

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    /**
     * Verifies that post throws agent error with plain text body.
     *
     * @return void
     */
    public function testPostThrowsAgentErrorWithPlainTextBody(): void
    {
        $symfonyHttpTransport = $this->transportReturning('Internal Server Error', 500);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Internal Server Error');

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $agentErrorException) {
            // Assert exact message - must NOT be double-wrapped with "HTTP request to agent failed:" prefix
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $agentErrorException->getMessage());
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        $chunks = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$chunks) {
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
        $symfonyHttpTransport = $this->transportReturning('Server error', 500);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function () {
        });
    }

    /**
     * Verifies that stream throws interrupted exception on timeout chunk.
     *
     * @return void
     */
    public function testStreamThrowsInterruptedExceptionOnTimeoutChunk(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: true, last: false),
        ]);

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('Stream timed out');

        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 1, 10, function () {
        });
    }

    /**
     * Verifies that stream stops on last chunk without publishing content.
     *
     * @return void
     */
    public function testStreamStopsOnLastChunkWithoutPublishingContent(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: true),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
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
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: false, content: 'data: {"type":"text","content":"hello"}\n\n'),
            $this->createChunk(timeout: false, last: true, content: 'data: {"type":"complete","text":"hello"}\n\n'),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
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
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(timeout: false, last: false, content: 'chunk1'),
            $this->createChunk(timeout: false, last: false, content: 'chunk2'),
            $this->createChunk(timeout: false, last: true, content: 'chunk3'),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received): bool {
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

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (): bool {
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
        $strandsException = new StrandsException('base error');
        $this->assertSame('base error', $strandsException->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $strandsException);

        $agentErrorException = new AgentErrorException('agent error', 422, 'ERR_001');
        $this->assertSame(422, $agentErrorException->statusCode);
        $this->assertSame('ERR_001', $agentErrorException->errorCode);
        $this->assertSame('agent error', $agentErrorException->getMessage());

        $streamInterruptedException = new StreamInterruptedException('stream dropped');
        $this->assertSame('stream dropped', $streamInterruptedException->getMessage());
        $this->assertInstanceOf(StrandsException::class, $streamInterruptedException);
    }

    /**
     * Verifies that agent error exception default status code.
     *
     * @return void
     */
    public function testAgentErrorExceptionDefaultStatusCode(): void
    {
        $agentErrorException = new AgentErrorException('error');
        $this->assertSame(0, $agentErrorException->statusCode);
        $this->assertNull($agentErrorException->errorCode);
        $this->assertNull($agentErrorException->responseBody);
    }

    /**
     * Verifies that agent error exception carries response body.
     *
     * @return void
     */
    public function testAgentErrorExceptionCarriesResponseBody(): void
    {
        $agentErrorException = new AgentErrorException('error', 422, responseBody: ['detail' => 'bad', 'fields' => ['name' => 'required']]);
        $this->assertSame(422, $agentErrorException->statusCode);
        $this->assertSame(['detail' => 'bad', 'fields' => ['name' => 'required']], $agentErrorException->responseBody);
    }

    /**
     * Verifies that post error includes response body.
     *
     * @return void
     */
    public function testPostErrorIncludesResponseBody(): void
    {
        $body = '{"detail":"Validation failed","errors":[{"field":"name","msg":"required"}]}';
        $symfonyHttpTransport = $this->transportReturning($body, 422);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame(422, $agentErrorException->statusCode);
            $this->assertIsArray($agentErrorException->responseBody);
            $this->assertSame('Validation failed', $agentErrorException->responseBody['detail']);
            $this->assertCount(1, $agentErrorException->responseBody['errors']);
        }
    }

    /**
     * Verifies that post error response body null for plain text.
     *
     * @return void
     */
    public function testPostErrorResponseBodyNullForPlainText(): void
    {
        $symfonyHttpTransport = $this->transportReturning('Internal Server Error', 500);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertNull($agentErrorException->responseBody);
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
        $symfonyHttpTransport = $this->transportReturning($body, 429);

        try {
            $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame(429, $agentErrorException->statusCode);
            $this->assertIsArray($agentErrorException->responseBody);
            $this->assertSame('RATE_LIMIT', $agentErrorException->responseBody['code']);
        }
    }

    /**
     * Verifies that post wraps non strands exception.
     *
     * @return void
     */
    public function testPostWrapsNonStrandsException(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('DNS resolution failed'));

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $agentErrorException) {
            $this->assertSame('HTTP request to agent failed: DNS resolution failed', $agentErrorException->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $agentErrorException);
            $this->assertInstanceOf(\RuntimeException::class, $agentErrorException->getPrevious());
        }
    }

    /**
     * Verifies that stream wraps non strands exception.
     *
     * @return void
     */
    public function testStreamWrapsNonStrandsException(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection reset'));

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        try {
            $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $agentErrorException) {
            $this->assertSame('Streaming request to agent failed: Connection reset', $agentErrorException->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $agentErrorException);
            $this->assertInstanceOf(\RuntimeException::class, $agentErrorException->getPrevious());
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('Agent returned HTTP 422: Specific detail', $agentErrorException->getMessage());
            $this->assertSame(422, $agentErrorException->statusCode);
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('Agent returned HTTP 422: ["Error 1","Error 2"]', $agentErrorException->getMessage());
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $agentErrorException->getMessage());
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
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame(400, $agentErrorException->statusCode);
            $this->assertSame('Agent returned HTTP 400: Bad Request', $agentErrorException->getMessage());
        }
    }

    /**
     * Verifies that post does not throw on 399 status code.
     *
     * @return void
     */
    public function testPostDoesNotThrowOn399StatusCode(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"text":"ok"}', 399);

        $result = $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

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
        $symfonyHttpTransport = $this->transportCapturingOptions($capturedOptions);

        $symfonyHttpTransport->post('http://example.com/invoke', ['X-Custom' => 'test-value'], '{}', 30, 10);

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
        $symfonyHttpTransport = $this->transportCapturingOptions($capturedOptions);

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{"message":"hi"}', 30, 10);

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
        $symfonyHttpTransport = $this->transportCapturingOptions($capturedOptions);

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 45, 5);

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
        $symfonyHttpTransport = $this->transportReturning('{"detail":"Unauthorized","code":"auth_failed"}', 401);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('auth_failed', $agentErrorException->errorCode);
        }
    }

    /**
     * Verifies that post error extracts error code from alternate key.
     *
     * @return void
     */
    public function testPostErrorExtractsErrorCodeFromAlternateKey(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"detail":"Rate limited","error_code":"throttled"}', 429);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('throttled', $agentErrorException->errorCode);
        }
    }

    /**
     * Verifies that post error code is null when absent.
     *
     * @return void
     */
    public function testPostErrorCodeIsNullWhenAbsent(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"detail":"Server error"}', 500);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertNull($agentErrorException->errorCode);
        }
    }

    /**
     * Verifies that post error code prefers code over error code.
     *
     * @return void
     */
    public function testPostErrorCodePrefersCodeOverErrorCode(): void
    {
        $symfonyHttpTransport = $this->transportReturning('{"detail":"Error","code":"primary_code","error_code":"fallback_code"}', 400);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            $this->assertSame('primary_code', $agentErrorException->errorCode);
        }
    }
}

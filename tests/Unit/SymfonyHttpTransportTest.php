<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Symfony Http Transport behavior for app integrations.
 *
 * Use this file when changing Symfony Http Transport or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

/**
 * Exercises Symfony Http Transport through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class SymfonyHttpTransportTest extends TestCase
{
    /**
     * Builds a mock stream so tests can assert live updates delivered to app callbacks.
     *
     * @param list<ChunkInterface> $chunks stream chunks delivered by the mock client.
     * @param ResponseInterface $response parsed agent result returned to the app.
     * @return ResponseStreamInterface mock stream used by Symfony transport tests.
     */
    private function createResponseStream(ResponseInterface $response, array $chunks): ResponseStreamInterface
    {
        return new class ($response, $chunks) implements ResponseStreamInterface {
            /**
             * Stores mock stream state for one simulated app request.
             *
             * @param list<ChunkInterface> $chunks stream chunks delivered by the mock client.
             * @param ResponseInterface $response response paired with each stream chunk.
             * @param int $position iterator position for the next chunk.
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
     * Creates a transport that streams controlled chunks to the app callback.
     *
     * @param list<ChunkInterface> $chunks stream chunks delivered by the mock client.
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param string $body request body the agent service will receive.
     * @return SymfonyHttpTransport transport wired to the mock stream.
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
     * Build one Symfony stream chunk used to model a live answer update.
     *
     * @param bool $isTimeout True when the app should see this chunk as an idle-timeout signal.
     * @param bool $isLast True when this chunk ends the user's stream.
     * @param string $content Bytes yielded by the response; empty means a control chunk with no UI update.
     * @return ChunkInterface Mock chunk consumed by the transport; never null.
     */
    private function createChunk(bool $isTimeout, bool $isLast, string $content = ''): ChunkInterface
    {
        $chunk = $this->createMock(ChunkInterface::class);
        $chunk->method('isTimeout')->willReturn($isTimeout);
        $chunk->method('isLast')->willReturn($isLast);
        $chunk->method('getContent')->willReturn($content);
        $chunk->method('isFirst')->willReturn(false);
        $chunk->method('getInformationalStatus')->willReturn(null);
        $chunk->method('getOffset')->willReturn(0);
        $chunk->method('getError')->willReturn(null);

        return $chunk;
    }

    /**
     * Builds a Symfony transport whose mock client returns the requested response.
     *
     * Use it to keep each caller-visible request scenario focused on its body and status.
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
     * Build a transport that captures the HTTP options generated for an app request.
     *
     * Use it when checking headers, body, or timeouts passed to Symfony.
     * The captured map shows exactly what leaves the client boundary.
     *
     * @param array<string, mixed> $capturedOptions options captured for assertions about user-facing request behavior.
     * @param-out array<string, mixed> $capturedOptions Reference filled with the options array passed to the mock client.
     * @return SymfonyHttpTransport Configured transport.
     */
    private function transportCapturingOptions(array &$capturedOptions): SymfonyHttpTransport
    {
        $mockResponse = new MockResponse('{"text":"ok"}', ['http_code' => 200]);
        $mockHttpClient = new MockHttpClient(function (
            string $method,
            string $url,
            array $options,
        ) use (&$capturedOptions, $mockResponse): MockResponse {
            $capturedOptions = $options;

            return $mockResponse;
        });

        return new SymfonyHttpTransport($mockHttpClient);
    }

    /**
     * Confirms post() returns decoded JSON so the app receives a clear answer or failure.
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
     * Confirms post() surfaces the agent error message from each documented error-response shape so the app receives a clear answer or failure.
     *
     * @param string $responseBody Body returned by the mock transport.
     * @param int $statusCode HTTP status the mock transport reports.
     * @param string $expectedMessage Substring AgentErrorException::getMessage() must contain.
     * @return void
     */
    #[DataProvider('postErrorBodyProvider')]
    public function testPostThrowsAgentErrorOnDocumentedErrorShape(string $responseBody, int $statusCode, string $expectedMessage): void
    {
        $symfonyHttpTransport = $this->transportReturning($responseBody, $statusCode);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage($expectedMessage);

        $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    /**
     * Cases for testPostThrowsAgentErrorOnDocumentedErrorShape().
     *
     * @return iterable<string, array{0: string, 1: int, 2: string}> Error body cases that keep transport failures clear to callers.
     */
    public static function postErrorBodyProvider(): iterable
    {
        yield 'JSON detail field, 422' => ['{"detail":"Something went wrong"}', 422, 'Something went wrong'];
        yield 'JSON error field, 400' => ['{"error":"Bad request"}', 400, 'Bad request'];
        yield 'Plain text body, 500' => ['Internal Server Error', 500, 'Internal Server Error'];
    }

    /**
     * Confirms post() throws strands exception on invalid JSON so the app receives a clear answer or failure.
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
     * Confirms stream() delivers chunks so the app receives a clear answer or failure.
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
     * Confirms stream() throws agent error on HTTP error so the app receives a clear answer or failure.
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
     * Confirms stream() throws interrupted exception on timeout chunk so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamThrowsInterruptedExceptionOnTimeoutChunk(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(isTimeout: true, isLast: false),
        ]);

        $this->expectException(StreamInterruptedException::class);
        $this->expectExceptionMessage('Stream timed out');

        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 1, 10, function () {
        });
    }

    /**
     * Confirms stream() stops on last chunk without publishing content so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamStopsOnLastChunkWithoutPublishingContent(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(isTimeout: false, isLast: true),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertSame([], $received);
    }

    /**
     * Confirms stream() delivers content from last chunk so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamDeliversContentFromLastChunk(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(isTimeout: false, isLast: false, content: 'data: {"type":"text","content":"hello"}\n\n'),
            $this->createChunk(isTimeout: false, isLast: true, content: 'data: {"type":"complete","text":"hello"}\n\n'),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertCount(2, $received);
        $this->assertStringContainsString('complete', $received[1]);
    }

    /**
     * Confirms stream() stops on callback return false so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamStopsOnCallbackReturnFalse(): void
    {
        $symfonyHttpTransport = $this->createTransportWithStreamChunks([
            $this->createChunk(isTimeout: false, isLast: false, content: 'chunk1'),
            $this->createChunk(isTimeout: false, isLast: false, content: 'chunk2'),
            $this->createChunk(isTimeout: false, isLast: true, content: 'chunk3'),
        ]);

        $received = [];
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (string $chunk) use (&$received): bool {
            $received[] = $chunk;

            // Stop after the first chunk so the caller can cancel streaming.
            return false;
        });

        $this->assertCount(1, $received);
        $this->assertSame('chunk1', $received[0]);
    }

    /**
     * Confirms stream() cancels response on callback return false so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamCancelsResponseOnCallbackReturnFalse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('cancel');

        $chunk = $this->createChunk(isTimeout: false, isLast: false, content: 'data');
        $stream = $this->createResponseStream($response, [$chunk]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->any())->method('request')->willReturn($response);
        $httpClient->method('stream')->willReturn($stream);

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        $cancelCalls = 0;
        $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function () use (&$cancelCalls): bool {
            $cancelCalls++;

            return false;
        });

        $this->assertSame(1, $cancelCalls, 'onChunk must run once before returning false cancels the response');
    }

    /**
     * Confirms the documented exception classes remain available so the app receives a clear answer or failure.
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
     * Confirms an agent error has a safe default status code so the UI can show a useful failure.
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
     * Confirms an agent error carries its response body so the UI can show or log useful failure details.
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
     * Confirms post() error includes response body so the app receives a clear answer or failure.
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
            // For example, a form can inspect structured field errors after the agent rejects the user's request.
            $this->assertSame(422, $agentErrorException->statusCode);
            $this->assertIsArray($agentErrorException->responseBody);
            $this->assertSame('Validation failed', $agentErrorException->responseBody['detail']);
            $this->assertCount(1, $agentErrorException->responseBody['errors']);
        }
    }

    /**
     * Confirms post() error response body null for plain text so the app receives a clear answer or failure.
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
            // For example, a plain-text gateway error has no structured fields for the UI, so its responseBody remains null.
            $this->assertNull($agentErrorException->responseBody);
        }
    }

    /**
     * Confirms stream() error includes response body so the app receives a clear answer or failure.
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
            // For example, a rate-limited live answer retains its machine code so the UI can offer a retry action.
            $this->assertSame(429, $agentErrorException->statusCode);
            $this->assertIsArray($agentErrorException->responseBody);
            $this->assertSame('RATE_LIMIT', $agentErrorException->responseBody['code']);
        }
    }

    /**
     * Confirms post() wraps non strands exception so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostWrapsNonStrandsException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->any())->method('request')
            ->willThrowException(new \RuntimeException('DNS resolution failed'));

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $strandsException) {
            // For example, DNS can fail before an agent responds; the app receives one consistent transport exception with the original cause.
            $this->assertSame('HTTP request to agent failed: DNS resolution failed', $strandsException->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $strandsException);
            $this->assertInstanceOf(\RuntimeException::class, $strandsException->getPrevious());
        }
    }

    /**
     * Confirms stream() wraps non strands exception so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamWrapsNonStrandsException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->any())->method('request')
            ->willThrowException(new \RuntimeException('Connection reset'));

        $symfonyHttpTransport = new SymfonyHttpTransport($httpClient);

        try {
            $symfonyHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function (): void {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $strandsException) {
            // For example, a socket can reset during generation; the app receives one streaming failure with the original cause attached.
            $this->assertSame('Streaming request to agent failed: Connection reset', $strandsException->getMessage());
            $this->assertNotInstanceOf(AgentErrorException::class, $strandsException);
            $this->assertInstanceOf(\RuntimeException::class, $strandsException->getPrevious());
        }
    }

    /**
     * Confirms post() error prefers detail over error so the app receives a clear answer or failure.
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
            // For example, validation detail is more useful to the form UI than the wrapper's generic error field.
            $this->assertSame('Agent returned HTTP 422: Specific detail', $agentErrorException->getMessage());
            $this->assertSame(422, $agentErrorException->statusCode);
        }
    }

    /**
     * Confirms post() error handles array detail so the app receives a clear answer or failure.
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
            // For example, validation can return several field errors; the app still needs a readable exception message.
            $this->assertSame('Agent returned HTTP 422: ["Error 1","Error 2"]', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms post() error falls back to content when no detail or error so the app receives a clear answer or failure.
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
            // For example, a custom wrapper can omit standard error fields; preserve its body so the app still gets diagnostic context.
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms stream() throws on 400 status code so the app receives a clear answer or failure.
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
            // For example, a custom live route can reject the request before sending events; the UI needs its original 400 message.
            $this->assertSame(400, $agentErrorException->statusCode);
            $this->assertSame('Agent returned HTTP 400: Bad Request', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms post() does not throw on 399 status code so the app receives a clear answer or failure.
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
     * Confirms post() sends headers to symfony so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostSendsHeadersToSymfony(): void
    {
        $capturedOptions = [];
        $symfonyHttpTransport = $this->transportCapturingOptions($capturedOptions);

        $symfonyHttpTransport->post('http://example.com/invoke', ['X-Custom' => 'test-value'], '{}', 30, 10);

        // Symfony normalizes headers to an indexed list, but the app's custom header must still reach the agent unchanged.
        $this->assertContains('X-Custom: test-value', $capturedOptions['headers']);
    }

    /**
     * Confirms post() sends body to symfony so the app receives a clear answer or failure.
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
     * Confirms post() sends timeout to symfony so the app receives a clear answer or failure.
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
     * Confirms AgentErrorException carries the expected errorCode for each documented error-body shape so the app receives a clear answer or failure.
     *
     * @param string $responseBody Body returned by the mock transport.
     * @param int $statusCode HTTP status the mock transport reports.
     * @param string|null $expectedErrorCode Expected code; null means the UI must fall back to status and message.
     * @return void
     */
    #[DataProvider('postErrorCodeProvider')]
    public function testPostErrorCodeExtractedFromDocumentedShape(string $responseBody, int $statusCode, ?string $expectedErrorCode): void
    {
        $symfonyHttpTransport = $this->transportReturning($responseBody, $statusCode);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, an auth failure exposes a stable code the app can use to choose its recovery screen.
            $this->assertSame($expectedErrorCode, $agentErrorException->errorCode);
        }
    }

    /**
     * Cases for testPostErrorCodeExtractedFromDocumentedShape().
     *
     * @return iterable<string, array{0: string, 1: int, 2: string|null}> HTTP error code cases that keep caller exceptions consistent.
     */
    public static function postErrorCodeProvider(): iterable
    {
        yield 'code field present' => ['{"detail":"Unauthorized","code":"auth_failed"}', 401, 'auth_failed'];
        yield 'error_code alternate key' => ['{"detail":"Rate limited","error_code":"throttled"}', 429, 'throttled'];
        yield 'no error code field present' => ['{"detail":"Server error"}', 500, null];
        yield 'code preferred over error_code' => ['{"detail":"Error","code":"primary_code","error_code":"fallback_code"}', 400, 'primary_code'];
    }
}

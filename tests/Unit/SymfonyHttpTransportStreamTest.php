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

/**
 * Verifies Symfony streaming delivers chunks, cancellation, and failures through the transport contract.
 *
 * Use these tests when changing HttpClient stream handling or callback cancellation.
 * They protect live updates and useful errors returned to the calling application.
 */
class SymfonyHttpTransportStreamTest extends TestCase
{
    /**
     * Builds a mock stream so tests can assert live updates delivered to app callbacks.
     *
     * @param list<ChunkInterface> $chunks Mock chunks; empty means the app receives no stream updates.
     * @param ResponseInterface $response Parsed agent response paired with the chunks; never null.
     * @return ResponseStreamInterface Mock stream that models response chunks; never null.
     */
    private function createResponseStream(ResponseInterface $response, array $chunks): ResponseStreamInterface
    {
        return new class ($response, $chunks) implements ResponseStreamInterface {
            /**
             * Stores mock stream state for one simulated app request.
             *
             * @param list<ChunkInterface> $chunks Mock chunks; empty means the iterator has no updates.
             * @param ResponseInterface $response Response paired with each stream chunk; never null.
             * @param int $position iterator position for the next chunk.
             */
            public function __construct(
                private readonly ResponseInterface $response,
                private readonly array $chunks,
                private int $position = 0,
            ) {
            }

            /**
             * Resets the mock response stream iterator before app events are replayed.
             *
             * @return void
             */
            public function rewind(): void
            {
                $this->position = 0;
            }

            /**
             * Returns the current mock response stream chunk for transport delivery.
             *
             * @return ChunkInterface Current mock stream chunk.
             */
            public function current(): ChunkInterface
            {
                return $this->chunks[$this->position];
            }

            /**
             * Returns the response associated with the current mock stream chunk.
             *
             * @return ResponseInterface Response associated with the current mock stream
             * chunk.
             */
            public function key(): ResponseInterface
            {
                return $this->response;
            }

            /**
             * Advances the mock response stream iterator to the next app update.
             *
             * @return void
             */
            public function next(): void
            {
                ++$this->position;
            }

            /**
             * Determines whether another mock chunk remains for the app callback.
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
     * @param list<ChunkInterface> $chunks Mock chunks; empty means the app receives no stream updates.
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param string $body Response body exposed on failure; empty means no structured error content.
     * @return SymfonyHttpTransport Transport wired to the mock stream; never null.
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
     * Builds one Symfony stream chunk used to model a live answer update.
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
     * @param string $responseBody Response body; empty models an endpoint with no content.
     * @param int $statusCode HTTP status code for the mocked response.
     * @return SymfonyHttpTransport Configured transport ready for a post() / stream() call.
     */
    private function transportReturning(string $responseBody, int $statusCode = 200): SymfonyHttpTransport
    {
        $mockResponse = new MockResponse($responseBody, ['http_code' => $statusCode]);

        return new SymfonyHttpTransport(new MockHttpClient($mockResponse));
    }

    /**
     * Confirms stream() delivers each content chunk so applications can update a live answer in order.
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
     * Confirms an HTTP error becomes AgentErrorException instead of being delivered as live answer content.
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
     * Confirms a timeout chunk becomes StreamInterruptedException so the UI can distinguish an incomplete answer.
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
     * Confirms a content-free last chunk closes the stream without adding phantom text to the live answer.
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
     * Confirms content carried by the last chunk reaches the callback before the live answer closes.
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
     * Confirms literal false from the callback stops delivery when the caller cancels a live answer.
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
     * Confirms caller cancellation also cancels the underlying response to stop network work.
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
     * Confirms a streaming HTTP error preserves its structured body for application recovery logic.
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
     * Confirms stream() wraps a non-Strands exception so callers receive one documented failure type.
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
     * Confirms status 400 becomes a caller exception before any invalid live content is published.
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
}

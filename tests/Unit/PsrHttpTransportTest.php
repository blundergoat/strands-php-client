<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
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

/**
 * Verifies PSR-18 requests carry the caller payload and surface decoded responses, agent errors, and transport failures.
 *
 * Use these tests when changing request factories, JSON handling, error parsing, or unsupported streaming behavior.
 * They protect applications that inject a PSR HTTP client instead of Symfony HttpClient.
 */
class PsrHttpTransportTest extends TestCase
{
    /**
     * Builds a PSR transport whose injected client returns one controlled response.
     * Use it to inspect caller-visible decoding and error behavior without network access.
     *
     * @param ResponseInterface $response Controlled HTTP response returned to the transport; never null.
     * @return PsrHttpTransport Transport wired to the controlled PSR collaborators.
     */
    private function transportReturning(
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

    /**
     * Builds a PSR response with the status and body an agent gateway could return.
     * Use it to model successful JSON, structured errors, plain text, or malformed JSON.
     *
     * @param int $statusCode HTTP status observed by the calling application.
     * @param string $body Raw response body; empty represents an agent response with no content.
     * @return ResponseInterface Controlled PSR response; never null.
     */
    private function psrResponse(int $statusCode, string $body): ResponseInterface
    {
        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($bodyStream);

        return $response;
    }

    /**
     * Confirms post() returns decoded JSON so callers receive usable agent response fields.
     *
     * @return void
     */
    public function testPostReturnsDecodedJson(): void
    {
        $response = $this->psrResponse(200, '{"text":"hello","session_id":"s1"}');
        $psrHttpTransport = $this->transportReturning($response);

        $result = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('hello', $result['text']);
        $this->assertSame('s1', $result['session_id']);
    }

    /**
     * Confirms post() surfaces each documented error message so callers can present an actionable failure.
     *
     * @param string $responseBody Non-empty body returned by the mock PSR-7 response.
     * @param int $statusCode HTTP status the mock PSR-7 response reports.
     * @param string $expectedMessage Non-empty message callers must receive in AgentErrorException.
     * @return void
     */
    #[DataProvider('postErrorBodyProvider')]
    public function testPostThrowsAgentErrorOnDocumentedErrorShape(string $responseBody, int $statusCode, string $expectedMessage): void
    {
        $response = $this->psrResponse($statusCode, $responseBody);
        $psrHttpTransport = $this->transportReturning($response);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage($expectedMessage);

        $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    /**
     * Lists documented error bodies and the message each caller exception must expose.
     *
     * @return iterable<string, array{0: string, 1: int, 2: string}> Non-empty error-body cases and caller messages.
     */
    public static function postErrorBodyProvider(): iterable
    {
        yield 'JSON detail field, 422' => ['{"detail":"Something went wrong"}', 422, 'Something went wrong'];
        yield 'JSON error field, 400' => ['{"error":"Bad request"}', 400, 'Bad request'];
        yield 'Plain text body, 500' => ['Internal Server Error', 500, 'Internal Server Error'];
    }

    /**
     * Confirms post() throws StrandsException for invalid JSON so the app receives a clear failure instead of a corrupt answer.
     *
     * @return void
     */
    public function testPostThrowsStrandsExceptionOnInvalidJson(): void
    {
        $response = $this->psrResponse(200, 'not json at all');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $strandsException) {
            // For example, an upstream proxy can return HTML instead of JSON; the app needs a clear response-shape error.
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $strandsException->getMessage());
        }
    }

    /**
     * Confirms post() wraps a PSR client failure so callers receive the library's documented exception type.
     *
     * @return void
     */
    public function testPostWrapsClientException(): void
    {
        $psr17Factory = new Psr17Factory();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->any())->method('sendRequest')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $psrHttpTransport = new PsrHttpTransport($httpClient, $psr17Factory, $psr17Factory);

        $this->expectException(StrandsException::class);
        $this->expectExceptionMessage('HTTP request to agent failed: Connection refused');

        $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
    }

    /**
     * Confirms post() forwards application headers to the PSR request unchanged.
     *
     * @return void
     */
    public function testPostSendsHeaders(): void
    {
        $psr17Factory = new Psr17Factory();
        $response = $this->psrResponse(200, '{"text":"ok"}');

        $capturedRequest = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->any())->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest, $response): ResponseInterface {
                $capturedRequest = $request;

                return $response;
            });

        $psrHttpTransport = new PsrHttpTransport($httpClient, $psr17Factory, $psr17Factory);

        $psrHttpTransport->post(
            'http://example.com/invoke',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{}',
            30,
            10,
        );

        $this->assertInstanceOf(RequestInterface::class, $capturedRequest);
        $this->assertSame('application/json', $capturedRequest->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $capturedRequest->getHeaderLine('Accept'));
    }

    /**
     * Confirms stream() throws StrandsException so the app receives a clear failure instead of invalid events.
     *
     * @return void
     */
    public function testStreamThrowsStrandsException(): void
    {
        $response = $this->psrResponse(200, '{}');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->stream('http://example.com/stream', [], '{}', 30, 10, function () {
            });
            $this->fail('Expected StrandsException');
        } catch (StrandsException $strandsException) {
            // For example, an app can accidentally request live updates with a PSR-18 client; the failure must point to the streaming transport.
            $this->assertStringContainsString('SSE streaming is not supported', $strandsException->getMessage());
            $this->assertStringContainsString('PsrHttpTransport', $strandsException->getMessage());
            $this->assertStringContainsString('symfony/http-client', $strandsException->getMessage());
            $this->assertStringContainsString('SymfonyHttpTransport', $strandsException->getMessage());
        }
    }

    /**
     * Confirms the timeout warning is logged once with context so developers can correct unsupported PSR-18 timeout settings.
     *
     * @return void
     */
    public function testTimeoutWarningLoggedOnceWithContext(): void
    {
        $psr17Factory = new Psr17Factory();
        $response = $this->psrResponse(200, '{"text":"ok"}');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->any())->method('sendRequest')->willReturn($response);

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

        $psrHttpTransport = new PsrHttpTransport($httpClient, $psr17Factory, $psr17Factory, $logger);

        // The first user request explains that timeout settings belong on the app's PSR-18 client.
        $firstResult = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
        // Later requests stay quiet so the same setup notice does not flood application logs.
        $secondResult = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 60, 20);

        $this->assertSame(['text' => 'ok'], $firstResult);
        $this->assertSame(['text' => 'ok'], $secondResult);
    }

    /**
     * Confirms post() prefers specific detail over a generic error so callers receive the most useful message.
     *
     * @return void
     */
    public function testPostErrorPrefersDetailOverError(): void
    {
        $response = $this->psrResponse(422, '{"detail":"Specific detail","error":"General error"}');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, validation detail is more useful to the form UI than the wrapper's generic error field.
            $this->assertSame('Agent returned HTTP 422: Specific detail', $agentErrorException->getMessage());
            $this->assertSame(422, $agentErrorException->statusCode);
        }
    }

    /**
     * Confirms post() renders an array of validation details as a readable caller error.
     *
     * @return void
     */
    public function testPostErrorHandlesArrayDetail(): void
    {
        $response = $this->psrResponse(422, '{"detail":["Error 1","Error 2"]}');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, validation can return several field errors; the app still needs a readable exception message.
            $this->assertSame('Agent returned HTTP 422: ["Error 1","Error 2"]', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms post() falls back to response content when standard error fields are absent.
     *
     * @return void
     */
    public function testPostErrorFallsBackToContentWhenNoDetailOrError(): void
    {
        $response = $this->psrResponse(500, '{"some_key":"value"}');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, a custom wrapper can omit standard error fields; preserve its body so the app still gets diagnostic context.
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms post() accepts status 399 so only documented HTTP errors become caller exceptions.
     *
     * @return void
     */
    public function testPostDoesNotThrowOn399StatusCode(): void
    {
        $response = $this->psrResponse(399, '{"text":"ok"}');
        $psrHttpTransport = $this->transportReturning($response);

        $result = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('ok', $result['text']);
    }

    /**
     * Confirms post() preserves a structured error body so forms can inspect field-level failure details.
     *
     * @return void
     */
    public function testPostErrorIncludesResponseBody(): void
    {
        $response = $this->psrResponse(422, '{"detail":"Validation failed","errors":[{"field":"name","msg":"required"}]}');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
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
     * Confirms a plain-text error has a null response body so apps do not mistake unstructured text for fields.
     *
     * @return void
     */
    public function testPostErrorResponseBodyNullForPlainText(): void
    {
        $response = $this->psrResponse(500, 'Internal Server Error');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, a plain-text gateway error has no structured fields for the UI, so its responseBody remains null.
            $this->assertNull($agentErrorException->responseBody);
        }
    }

    /**
     * Confirms post() preserves an existing StrandsException so callers retain its original failure details.
     *
     * @return void
     */
    public function testPostDoesNotDoubleWrapStrandsException(): void
    {
        $response = $this->psrResponse(200, 'not json');
        $psrHttpTransport = $this->transportReturning($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $strandsException) {
            // For example, invalid JSON is already a caller-ready client error and must not be hidden behind a second transport message.
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $strandsException->getMessage());
            $this->assertStringNotContainsString('HTTP request to agent failed', $strandsException->getMessage());
        }
    }
}

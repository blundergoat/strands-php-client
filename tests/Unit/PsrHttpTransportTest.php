<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Psr Http Transport behavior for app integrations.
 *
 * Use this file when changing Psr Http Transport or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

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
 * Exercises Psr Http Transport through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class PsrHttpTransportTest extends TestCase
{
    /**
     * Create transport for the test scenario.
     *
     * @param ResponseInterface $response Parsed response data for the operation.
     * @return PsrHttpTransport Value produced by the method.
     */
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

    /**
     * Create response for the test scenario.
     *
     * @param int $statusCode HTTP status code for the operation.
     * @param string $body Request or response body used by the scenario.
     * @return ResponseInterface Value produced by the method.
     */
    private function createResponse(int $statusCode, string $body): ResponseInterface
    {
        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($bodyStream);

        return $response;
    }

    /**
     * Confirms post() returns decoded JSON so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostReturnsDecodedJson(): void
    {
        $response = $this->createResponse(200, '{"text":"hello","session_id":"s1"}');
        $psrHttpTransport = $this->createTransport($response);

        $result = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('hello', $result['text']);
        $this->assertSame('s1', $result['session_id']);
    }

    /**
     * Confirms post() surfaces the agent error message from each documented error-response shape so the app receives a clear answer or failure.
     *
     * @param string $responseBody Body returned by the mock PSR-7 response.
     * @param int $statusCode HTTP status the mock PSR-7 response reports.
     * @param string $expectedMessage Substring AgentErrorException::getMessage() must contain.
     * @return void
     */
    #[DataProvider('postErrorBodyProvider')]
    public function testPostThrowsAgentErrorOnDocumentedErrorShape(string $responseBody, int $statusCode, string $expectedMessage): void
    {
        $response = $this->createResponse($statusCode, $responseBody);
        $psrHttpTransport = $this->createTransport($response);

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage($expectedMessage);

        $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
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
        $response = $this->createResponse(200, 'not json at all');
        $psrHttpTransport = $this->createTransport($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $strandsException) {
            // For example, an upstream proxy can return HTML instead of JSON; the app needs a clear response-shape error.
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $strandsException->getMessage());
        }
    }

    /**
     * Confirms post() wraps client exception so the app receives a clear answer or failure.
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
     * Confirms post() sends headers so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostSendsHeaders(): void
    {
        $psr17Factory = new Psr17Factory();
        $response = $this->createResponse(200, '{"text":"ok"}');

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
     * Confirms stream() throws strands exception so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testStreamThrowsStrandsException(): void
    {
        $response = $this->createResponse(200, '{}');
        $psrHttpTransport = $this->createTransport($response);

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
        $response = $this->createResponse(200, '{"text":"ok"}');

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
     * Confirms post() error prefers detail over error so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostErrorPrefersDetailOverError(): void
    {
        $response = $this->createResponse(422, '{"detail":"Specific detail","error":"General error"}');
        $psrHttpTransport = $this->createTransport($response);

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
     * Confirms post() error handles array detail so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostErrorHandlesArrayDetail(): void
    {
        $response = $this->createResponse(422, '{"detail":["Error 1","Error 2"]}');
        $psrHttpTransport = $this->createTransport($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
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
        $response = $this->createResponse(500, '{"some_key":"value"}');
        $psrHttpTransport = $this->createTransport($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, a custom wrapper can omit standard error fields; preserve its body so the app still gets diagnostic context.
            $this->assertSame('Agent returned HTTP 500: {"some_key":"value"}', $agentErrorException->getMessage());
        }
    }

    /**
     * Confirms post() does not throw on 399 status code so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostDoesNotThrowOn399StatusCode(): void
    {
        $response = $this->createResponse(399, '{"text":"ok"}');
        $psrHttpTransport = $this->createTransport($response);

        $result = $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);

        $this->assertSame('ok', $result['text']);
    }

    /**
     * Confirms post() error includes response body so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostErrorIncludesResponseBody(): void
    {
        $response = $this->createResponse(422, '{"detail":"Validation failed","errors":[{"field":"name","msg":"required"}]}');
        $psrHttpTransport = $this->createTransport($response);

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
     * Confirms post() error response body null for plain text so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostErrorResponseBodyNullForPlainText(): void
    {
        $response = $this->createResponse(500, 'Internal Server Error');
        $psrHttpTransport = $this->createTransport($response);

        try {
            $psrHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, a plain-text gateway error has no structured fields for the UI, so its responseBody remains null.
            $this->assertNull($agentErrorException->responseBody);
        }
    }

    /**
     * Confirms post() does not double wrap strands exception so the app receives a clear answer or failure.
     *
     * @return void
     */
    public function testPostDoesNotDoubleWrapStrandsException(): void
    {
        $response = $this->createResponse(200, 'not json');
        $psrHttpTransport = $this->createTransport($response);

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

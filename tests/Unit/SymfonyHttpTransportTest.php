<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use StrandsPhpClient\Http\SymfonyHttpTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies Symfony POST requests decode agent responses and preserve caller-visible errors.
 *
 * Use these tests when changing request options, JSON handling, or error extraction.
 * They protect application answers, validation details, and transport diagnostics.
 */
class SymfonyHttpTransportTest extends TestCase
{
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
     * Builds a transport that captures the HTTP options generated for an app request.
     *
     * Use it when checking headers, body, or timeouts passed to Symfony.
     * The captured map shows exactly what leaves the client boundary.
     *
     * @param array<string, mixed> $capturedOptions Options captured for request assertions; empty before the mock runs.
     * @param-out array<string, mixed> $capturedOptions Reference filled with the options array passed to the mock client.
     * @return SymfonyHttpTransport Configured transport; never null.
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
     * Confirms post() returns decoded JSON so callers receive usable agent response fields.
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
     * Confirms post() surfaces each documented error message so callers can present an actionable failure.
     *
     * @param string $responseBody Non-empty documented error body returned by the mock transport.
     * @param int $statusCode HTTP status the mock transport reports.
     * @param string $expectedMessage Non-empty message callers must receive in AgentErrorException.
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
        $mockResponse = new MockResponse('not json at all', [
            'http_code' => 200,
        ]);
        $mockHttpClient = new MockHttpClient($mockResponse);
        $symfonyHttpTransport = new SymfonyHttpTransport($mockHttpClient);

        try {
            $symfonyHttpTransport->post('http://example.com/invoke', [], '{}', 30, 10);
            $this->fail('Expected StrandsException was not thrown');
        } catch (StrandsException $strandsException) {
            // For example, a plain-text agent response should produce one parsing error, not a second transport wrapper message.
            $this->assertSame('Expected JSON object from http://example.com/invoke, got null', $strandsException->getMessage());
        }
    }

    /**
     * Confirms documented exception classes remain available so existing application catch blocks stay compatible.
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
     * Confirms post() preserves a structured error body so forms can inspect field-level failure details.
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
     * Confirms a plain-text error has a null response body so apps do not mistake unstructured text for fields.
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
     * Confirms post() wraps a non-Strands exception so callers receive one documented failure type.
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
     * Confirms post() prefers specific detail over a generic error so callers receive the most useful message.
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
     * Confirms post() renders an array of validation details as a readable caller error.
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
     * Confirms post() falls back to response content when standard error fields are absent.
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
     * Confirms post() accepts status 399 so only documented HTTP errors become caller exceptions.
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
     * Confirms post() forwards application headers to the Symfony HTTP client unchanged.
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
     * Confirms post() forwards the caller's JSON body to the Symfony HTTP client unchanged.
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
     * Confirms post() forwards timeout settings so callers receive the configured wait behavior.
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
     * Confirms AgentErrorException exposes each documented error code for application recovery logic.
     *
     * @param string $responseBody Non-empty documented error body returned by the mock transport.
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
     * Lists documented error-code shapes and the nullable code callers should receive.
     *
     * @return iterable<string, array{0: string, 1: int, 2: string|null}> Error-code cases; null means callers fall back to status and message.
     */
    public static function postErrorCodeProvider(): iterable
    {
        yield 'code field present' => ['{"detail":"Unauthorized","code":"auth_failed"}', 401, 'auth_failed'];
        yield 'error_code alternate key' => ['{"detail":"Rate limited","error_code":"throttled"}', 429, 'throttled'];
        yield 'no error code field present' => ['{"detail":"Server error"}', 500, null];
        yield 'code preferred over error_code' => ['{"detail":"Error","code":"primary_code","error_code":"fallback_code"}', 400, 'primary_code'];
    }
}

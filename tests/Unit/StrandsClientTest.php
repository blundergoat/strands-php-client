<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Auth\NullAuth;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Context\AgentInput;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies StrandsClient turns caller intent into one safe, observable HTTP operation.
 *
 * It protects invoke(), shared request construction, retry behaviour, and middleware lifecycle.
 * Use these scenarios when changing client orchestration outside the typed streaming loop.
 */
class StrandsClientTest extends TestCase
{
    /**
     * Clears shared test state after a user-request scenario so the next test represents a fresh app session.
     * Use it automatically after client-orchestration checks.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['__strands_class_exists_overrides']);

        parent::tearDown();
    }

    /**
     * Loads captured fixture data for a realistic client-orchestration scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $fixtureName Non-empty JSON fixture filename under tests/Fixtures/.
     * @return array<string, mixed> Decoded response fields; an empty object exercises omitted caller-visible fields.
     */
    private function loadFixture(string $fixtureName): array
    {
        $path = __DIR__ . '/../Fixtures/' . $fixtureName;

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Loads captured fixture data for a realistic client-orchestration scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $fixtureName Non-empty SSE fixture filename under tests/Fixtures/.
     * @return string Captured SSE bytes; empty means no event reaches the app callback.
     */
    private function loadSseFixture(string $fixtureName): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $fixtureName);
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the client-orchestration scenario must inspect requests or delivery order.
     *
     * @param array<string, mixed> $responseData Parsed agent response; an empty array models a response with no fields.
     * @return HttpTransport Mock transport that returns the supplied response; never null.
     */
    private function createMockTransport(array $responseData): HttpTransport
    {
        $mockTransport = $this->createMock(HttpTransport::class);
        $mockTransport->method('post')->willReturn($responseData);

        return $mockTransport;
    }

    /**
     * Checks invoke request and response logs contain the fields operators need for tracing.
     * Use it to keep lifecycle diagnostics consistent across client scenarios.
     *
     * @param list<array{message: string, context: array<string, mixed>}> $debugCalls Captured logs; empty means no lifecycle was recorded.
     * @return void
     */
    private function assertInvokeDebugCalls(array $debugCalls): void
    {
        $this->assertSame('Strands invoke request', $debugCalls[0]['message']);
        $this->assertSame('http://localhost:8081/invoke', $debugCalls[0]['context']['url']);
        $this->assertSame('sess-log', $debugCalls[0]['context']['session_id']);
        $this->assertSame('Strands invoke response', $debugCalls[1]['message']);
        $this->assertSame('test-session-001', $debugCalls[1]['context']['session_id']);
        $this->assertSame(150, $debugCalls[1]['context']['input_tokens']);
        $this->assertSame(280, $debugCalls[1]['context']['output_tokens']);
        $this->assertSame(0, $debugCalls[1]['context']['tools_used']);
        $this->assertArrayHasKey('agent', $debugCalls[1]['context']);
        $this->assertArrayHasKey('interrupted', $debugCalls[1]['context']);
        $this->assertArrayHasKey('structured_output', $debugCalls[1]['context']);
    }

    /**
     * Verifies invoke() returns a hydrated AgentResponse so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeReturnsHydratedResponse(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: new NullAuth(),
            ),
            transport: $transport,
        );

        $response = $strandsClient->invoke(
            message: 'Should we migrate to microservices?',
            context: AgentContext::create()->withMetadata('persona', 'analyst'),
            sessionId: 'test-session-001',
        );

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertStringContainsString('BLUF', $response->text);
        $this->assertSame('test-session-001', $response->sessionId);
        $this->assertSame(150, $response->usage->inputTokens);
        $this->assertSame(280, $response->usage->outputTokens);
        $this->assertSame([], $response->toolsUsed);
    }

    /**
     * Verifies invoke() without session id so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeWithoutSessionId(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $fixture['session_id'] = null;
        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'What is 2+2?');

        $this->assertNull($response->sessionId);
    }

    /**
     * Verifies invoke() without context so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeWithoutContext(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Hello');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies invoke() sends the expected payload so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeSendsCorrectPayload(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8081/invoke',
                $this->callback(fn (array $headers) => $headers['Content-Type'] === 'application/json'),
                $this->callback(function (string $body) {
                    $decodedRequestPayload = json_decode($body, true);

                    return $decodedRequestPayload['message'] === 'Test message'
                        && $decodedRequestPayload['session_id'] === 'sess-123'
                        && $decodedRequestPayload['context']['metadata']['persona'] === 'skeptic';
                }),
                120,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $strandsClient->invoke(
            message: 'Test message',
            context: AgentContext::create()->withMetadata('persona', 'skeptic'),
            sessionId: 'sess-123',
        );

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies invoke() builds one URL from an endpoint with a trailing slash so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeStripsTrailingSlash(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with('http://localhost:8081/invoke', $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies authentication receives the final invoke URL so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeAuthReceivesInvokeUrl(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->once())
            ->method('authenticate')
            ->with(
                $this->anything(),
                'POST',
                'http://localhost:8081/invoke',
                $this->anything(),
            )
            ->willReturnArgument(0);

        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies authentication receives the final stream URL so callers get the configured request behavior.
     *
     * @return void
     */
    public function testStreamAuthReceivesStreamUrl(): void
    {
        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->once())
            ->method('authenticate')
            ->with(
                $this->anything(),
                'POST',
                'http://localhost:8081/stream',
                $this->anything(),
            )
            ->willReturnArgument(0);

        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(message: 'Test', onEvent: function () {
        });

        $this->assertInstanceOf(\StrandsPhpClient\Streaming\StreamResult::class, $streamResult);
    }

    /**
     * Verifies invoke() logs request and response so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeLogsRequestAndResponse(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $logger = $this->createMock(LoggerInterface::class);
        $debugCalls = [];
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context) use (&$debugCalls): void {
                $debugCalls[] = ['message' => $message, 'context' => $context];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->invoke(message: 'Test', sessionId: 'sess-log');
        $this->assertInvokeDebugCalls($debugCalls);
    }

    /**
     * Verifies invoke() accepts agent input so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeAcceptsAgentInput(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $headers, string $body) {
                $decoded = json_decode($body, true);
                // A rich request sends the user's text and attachments as a content-block map.
                \PHPUnit\Framework\Assert::assertIsArray($decoded['message']);
                \PHPUnit\Framework\Assert::assertArrayHasKey('content', $decoded['message']);
                \PHPUnit\Framework\Assert::assertCount(2, $decoded['message']['content']);
                \PHPUnit\Framework\Assert::assertSame('text', $decoded['message']['content'][0]['type']);
                \PHPUnit\Framework\Assert::assertSame('image', $decoded['message']['content'][1]['type']);

                return ['text' => 'I see a cat', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text("What's in this image?")
            ->withImage('base64cat', 'image/jpeg');

        $response = $strandsClient->invoke($input);

        $this->assertSame('I see a cat', $response->text);
    }

    /**
     * Verifies invoke() accepts plain string with agent input signature so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeAcceptsPlainStringWithAgentInputSignature(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $headers, string $body) {
                $decoded = json_decode($body, true);
                // A normal chat-box submission keeps the compact string wire shape expected by 1.x wrappers.
                \PHPUnit\Framework\Assert::assertSame('Hello', $decoded['message']);

                return ['text' => 'Hi', 'usage' => []];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $strandsClient->invoke('Hello');

        $this->assertSame('Hi', $response->text);
    }

    /**
     * Verifies invoke() with text only agent input sends string so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeWithTextOnlyAgentInputSendsString(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $headers, string $body) {
                $decoded = json_decode($body, true);
                // A text-only AgentInput keeps the same compact wire string as a plain chat submission.
                \PHPUnit\Framework\Assert::assertSame('Simple text', $decoded['message']);

                return ['text' => 'OK', 'usage' => []];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text('Simple text');
        $response = $strandsClient->invoke($input);

        $this->assertSame('OK', $response->text);
    }

    /**
     * Verifies client construction fails clearly when no transport is available so callers get the configured request behavior.
     *
     * @return void
     */
    public function testConstructorThrowsWhenNoTransportCanBeDetected(): void
    {
        require_once __DIR__ . '/../Support/StrandsFunctionOverrides.php';

        $GLOBALS['__strands_class_exists_overrides'][\Symfony\Component\HttpClient\HttpClient::class] = false;

        try {
            $this->expectException(StrandsException::class);
            $this->expectExceptionMessage('No HTTP transport available');

            new StrandsClient(config: new StrandsConfig(endpoint: 'http://localhost:8081'));
        } finally {
            unset($GLOBALS['__strands_class_exists_overrides']);
        }
    }

    /**
     * Verifies automatic transport errors list every supported setup path so callers get the configured request behavior.
     *
     * @return void
     */
    public function testDetectTransportErrorMessageContainsAllParts(): void
    {
        require_once __DIR__ . '/../Support/StrandsFunctionOverrides.php';

        $GLOBALS['__strands_class_exists_overrides'][\Symfony\Component\HttpClient\HttpClient::class] = false;

        try {
            new StrandsClient(config: new StrandsConfig(endpoint: 'http://localhost:8081'));
            $this->fail('Expected StrandsException');
        } catch (StrandsException $exception) {
            // A fresh app install with neither an injected transport nor Symfony HTTP must receive actionable setup guidance.
            $this->assertStringContainsString('No HTTP transport available', $exception->getMessage());
            $this->assertStringContainsString('symfony/http-client', $exception->getMessage());
            $this->assertStringContainsString('invoke + streaming support', $exception->getMessage());
            $this->assertStringContainsString('PsrHttpTransport', $exception->getMessage());
        } finally {
            unset($GLOBALS['__strands_class_exists_overrides']);
        }
    }

    /**
     * Verifies middleware cleanup failures retain operation context in logs so callers get the configured request behavior.
     *
     * @return void
     * @throws \RuntimeException When the observer stub simulates logging failure.
     */
    public function testMiddlewareAfterResponseExceptionLogsContext(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $requestMiddleware = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            /**
             * Simulates middleware changing the outgoing request before authentication and delivery to the agent.
             * Use it inside a scenario where the app customizes what the user sends.
             *
             * @param string $url Non-empty request URL observed by middleware.
             * @param array<string, string> $headers Caller headers; empty means no custom headers were supplied.
             * @param string $body Request body; empty means middleware receives no payload content.
             * @return array{headers: array<string, string>, body: string} Non-empty request map; its header map may be empty.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                return ['headers' => $headers, 'body' => $body];
            }

            /**
             * Simulates middleware observing the completed request for app logging or cleanup.
             * A null error means the user's request completed without a transport failure.
             *
             * @param string $url Non-empty request URL observed by middleware.
             * @param int $statusCode HTTP status code for the operation.
             * @param float $durationMs Operation duration in milliseconds.
             * @param \Throwable|null $error Request failure; null means the user's call completed successfully.
             * @return void
             * @throws \RuntimeException When the stub simulates observer failure logging.
             */
            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
                throw new \RuntimeException('Middleware boom');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Middleware afterResponse threw an exception',
                $this->callback(function (array $context) use ($requestMiddleware): bool {
                    return isset($context['middleware'])
                        && isset($context['error'])
                        && $context['middleware'] === $requestMiddleware::class
                        && $context['error'] === 'Middleware boom';
                }),
            );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
            middleware: [$requestMiddleware],
        );

        // A telemetry teardown failure is logged but must not replace the successful answer shown to the user.
        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Verifies stream() strips trailing slash from endpoint so callers get the configured request behavior.
     *
     * @return void
     */
    public function testStreamStripsTrailingSlashFromEndpoint(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with('http://localhost:8081/stream', $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData) {
                $onChunk->__invoke($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(message: 'Test', onEvent: function () {
        });

        $this->assertInstanceOf(\StrandsPhpClient\Streaming\StreamResult::class, $streamResult);
    }

    /**
     * Verifies invoke() rejects empty string so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeRejectsEmptyString(): void
    {
        $transport = $this->createMockTransport([]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $strandsClient->invoke(message: '');
    }

    /**
     * Verifies stream() rejects empty string so callers get the configured request behavior.
     *
     * @return void
     */
    public function testStreamRejectsEmptyString(): void
    {
        $transport = $this->createMockTransport([]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $strandsClient->stream(message: '', onEvent: function () {
        });
    }

    /**
     * Verifies invoke() accepts interrupt response with empty text so callers get the configured request behavior.
     *
     * @return void
     */
    public function testInvokeAcceptsInterruptResponseWithEmptyText(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        // An approval response has no chat text but its interrupt block still forms a valid user action.
        $input = \StrandsPhpClient\Context\AgentInput::interruptResponse('int-123', 'Approved');
        $response = $strandsClient->invoke(message: $input);

        $this->assertNotEmpty($response->text);
    }

    /**
     * Verifies authentication signs the body after app middleware has modified it.
     *
     * @return void
     */
    public function testMiddlewareRunsBeforeAuthSoSignatureCoversModifiedBody(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        // Capture the final request so the test can prove authentication sees what the middleware changed for the user.
        $authReceivedBody = null;
        $authReceivedHeaders = null;

        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->any())->method('authenticate')
            ->willReturnCallback(function (
                array $headers,
                string $method,
                string $url,
                string $body,
            ) use (&$authReceivedBody, &$authReceivedHeaders): array {
                $authReceivedBody = $body;
                $authReceivedHeaders = $headers;
                $headers['Authorization'] = 'signed';

                return $headers;
            });

        // This app middleware enriches the user's payload and header before the request is signed.
        $requestMiddleware = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            /**
             * Simulates middleware changing the outgoing request before authentication and delivery to the agent.
             * Use it inside a scenario where the app customizes what the user sends.
             *
             * @param string $url Non-empty request URL observed by middleware.
             * @param array<string, string> $headers Caller headers; empty means the app supplied no custom headers.
             * @param string $body Request body; empty means middleware receives no payload content.
             * @return array{headers: array<string, string>, body: string} Non-empty request map; its header map may be empty.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                $decoded = json_decode($body, true);
                $decoded['injected'] = true;
                $headers['X-Custom'] = 'from-middleware';

                return ['headers' => $headers, 'body' => json_encode($decoded)];
            }

            /**
             * Simulates middleware observing the completed request for app logging or cleanup.
             * A null error means the user's request completed without a transport failure.
             *
             * @param string $url Non-empty request URL observed by middleware.
             * @param int $statusCode HTTP status code for the operation.
             * @param float $durationMs Operation duration in milliseconds.
             * @param \Throwable|null $error Request failure; null means the user's call completed successfully.
             * @return void
             */
            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
            }
        };

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', auth: $auth),
            transport: $transport,
            middleware: [$requestMiddleware],
        );

        $strandsClient->invoke(message: 'Test');

        // Signing the enriched body prevents middleware changes from invalidating authentication.
        $this->assertNotNull($authReceivedBody);
        $decoded = json_decode($authReceivedBody, true);
        $this->assertTrue($decoded['injected'], 'Auth must receive the body after middleware modification');

        // Signing the enriched header set proves authentication covers the exact request sent to the agent.
        $this->assertSame('from-middleware', $authReceivedHeaders['X-Custom']);
    }
}

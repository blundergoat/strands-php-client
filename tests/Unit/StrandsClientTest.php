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
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;

class StrandsClientTest extends TestCase
{
    /**
     * Load fixture for the test scenario.
     *
     * @param string $name Fixture name or DTO name under test.
     * @return array<string, mixed> Decoded fixture or processed configuration array.
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/' . $name;

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Load a raw SSE fixture file as a string.
     *
     * @param string $name Fixture file name under tests/Fixtures/.
     * @return string Raw fixture contents.
     */
    private function loadSseFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Build a transport whose post() throws once and then returns the given payload on every subsequent call.
     * Keeps retry-counting state out of test bodies so each retry test reads linearly.
     *
     * @param \Throwable $throwOnce Exception thrown by the first call to post().
     * @param array<string, mixed> $thenReturn Payload returned by every call after the first.
     * @return HttpTransport Mocked transport with the throw-then-return sequence wired up.
     */
    private function transportThrowsOnceThenReturns(\Throwable $throwOnce, array $thenReturn): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount, $throwOnce, $thenReturn): array {
                $callCount++;
                if ($callCount === 1) {
                    throw $throwOnce;
                }

                return $thenReturn;
            });

        return $transport;
    }

    /**
     * Create mock transport for the test scenario.
     *
     * @param array<string, mixed> $response Parsed response data for the operation.
     * @return HttpTransport Value produced by the method.
     */
    private function createMockTransport(array $response): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('post')->willReturn($response);

        return $mock;
    }

    /**
     * Verifies that invoke returns hydrated response.
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
     * Verifies that invoke without session ID.
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
     * Verifies that invoke without context.
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
     * Verifies that invoke sends correct payload.
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
                    $data = json_decode($body, true);

                    return $data['message'] === 'Test message'
                        && $data['session_id'] === 'sess-123'
                        && $data['context']['metadata']['persona'] === 'skeptic';
                }),
                120,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $strandsClient->invoke(
            message: 'Test message',
            context: AgentContext::create()->withMetadata('persona', 'skeptic'),
            sessionId: 'sess-123',
        );
    }

    /**
     * Verifies that invoke strips trailing slash.
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

        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that invoke auth receives invoke URL.
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

        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that stream auth receives stream URL.
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
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $strandsClient->stream(message: 'Test', onEvent: function () {
        });
    }

    /**
     * Verifies that invoke retries on retryable status code.
     *
     * @return void
     */
    public function testInvokeRetriesOnRetryableStatusCode(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->transportThrowsOnceThenReturns(
            new AgentErrorException('Service unavailable', statusCode: 503),
            $fixture,
        );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertStringContainsString('BLUF', $response->text);
    }

    /**
     * Verifies that invoke retries on generic strands exception.
     *
     * @return void
     */
    public function testInvokeRetriesOnGenericStrandsException(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->transportThrowsOnceThenReturns(
            new StrandsException('Network error'),
            $fixture,
        );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertStringContainsString('BLUF', $response->text);
    }

    /**
     * Verifies that invoke does not retry non retryable status code.
     *
     * @return void
     */
    public function testInvokeDoesNotRetryNonRetryableStatusCode(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new AgentErrorException('Bad request', statusCode: 400);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 3,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Bad request');

        try {
            $strandsClient->invoke(message: 'Test');
        } catch (AgentErrorException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 400');

            throw $e;
        }
    }

    /**
     * Verifies that invoke throws after max retries.
     *
     * @return void
     */
    public function testInvokeThrowsAfterMaxRetries(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')
            ->willThrowException(new AgentErrorException('Service unavailable', statusCode: 503));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Service unavailable');

        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that invoke does not retry on 401.
     *
     * @return void
     */
    public function testInvokeDoesNotRetryOnUnauthorized(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new AgentErrorException('Unauthorized', statusCode: 401);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 3,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Unauthorized');

        try {
            $strandsClient->invoke(message: 'Test');
        } catch (AgentErrorException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 401');

            throw $e;
        }
    }

    /**
     * Verifies that config accepts boundary max retries.
     *
     * @return void
     */
    public function testConfigAcceptsBoundaryMaxRetries(): void
    {
        $strandsConfigZeroRetries = new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 0);
        $this->assertSame(0, $strandsConfigZeroRetries->maxRetries);

        $strandsConfigMaxRetries = new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 20);
        $this->assertSame(20, $strandsConfigMaxRetries->maxRetries);
    }

    /**
     * Verifies that config rejects zero timeout.
     *
     * @return void
     */
    public function testConfigRejectsZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 0);
    }

    /**
     * Verifies that config rejects negative connect timeout.
     *
     * @return void
     */
    public function testConfigRejectsNegativeConnectTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connectTimeout must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', connectTimeout: -1);
    }

    /**
     * Verifies that config rejects zero retry delay ms.
     *
     * @return void
     */
    public function testConfigRejectsZeroRetryDelayMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryDelayMs must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryDelayMs: 0);
    }

    /**
     * Verifies that config rejects invalid endpoint URL.
     *
     * @return void
     */
    public function testConfigRejectsInvalidEndpointUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid endpoint URL');

        new StrandsConfig(endpoint: 'not a url');
    }

    /**
     * Verifies that config rejects max retries above 20.
     *
     * @return void
     */
    public function testConfigRejectsMaxRetriesAboveUpperBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be between 0 and 20');

        new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 21);
    }

    /**
     * Verifies that config rejects negative max retries.
     *
     * @return void
     */
    public function testConfigRejectsNegativeMaxRetries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be between 0 and 20');

        new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: -1);
    }

    /**
     * Verifies that config default values.
     *
     * @return void
     */
    public function testConfigDefaultValues(): void
    {
        $strandsConfig = new StrandsConfig(endpoint: 'http://localhost:8081');

        $this->assertSame(120, $strandsConfig->timeout);
        $this->assertSame(10, $strandsConfig->connectTimeout);
        $this->assertSame(0, $strandsConfig->maxRetries);
        $this->assertSame(500, $strandsConfig->retryDelayMs);
        $this->assertSame([429, 502, 503, 504], $strandsConfig->retryableStatusCodes);
    }

    /**
     * Verifies that config accepts timeout boundary.
     *
     * @return void
     */
    public function testConfigAcceptsTimeoutBoundary(): void
    {
        $strandsConfigTimeout = new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 1);
        $this->assertSame(1, $strandsConfigTimeout->timeout);

        $strandsConfigConnectTimeout = new StrandsConfig(endpoint: 'http://localhost:8081', connectTimeout: 1);
        $this->assertSame(1, $strandsConfigConnectTimeout->connectTimeout);

        $strandsConfigRetryDelay = new StrandsConfig(endpoint: 'http://localhost:8081', retryDelayMs: 1);
        $this->assertSame(1, $strandsConfigRetryDelay->retryDelayMs);
    }

    /**
     * Verifies that invoke logs request and response.
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

        // Request log must include url and session_id
        $this->assertSame('Strands invoke request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('session_id', $debugCalls[0]['context']);
        $this->assertSame('http://localhost:8081/invoke', $debugCalls[0]['context']['url']);
        $this->assertSame('sess-log', $debugCalls[0]['context']['session_id']);

        // Response log must include session_id, agent, input_tokens, output_tokens, tools_used
        $this->assertSame('Strands invoke response', $debugCalls[1]['message']);
        $this->assertArrayHasKey('session_id', $debugCalls[1]['context']);
        $this->assertArrayHasKey('agent', $debugCalls[1]['context']);
        $this->assertArrayHasKey('input_tokens', $debugCalls[1]['context']);
        $this->assertArrayHasKey('output_tokens', $debugCalls[1]['context']);
        $this->assertArrayHasKey('tools_used', $debugCalls[1]['context']);
        $this->assertSame('test-session-001', $debugCalls[1]['context']['session_id']);
        $this->assertSame(150, $debugCalls[1]['context']['input_tokens']);
        $this->assertSame(280, $debugCalls[1]['context']['output_tokens']);
        $this->assertSame(0, $debugCalls[1]['context']['tools_used']);
    }

    /**
     * Verifies that retry logs warning.
     *
     * @return void
     */
    public function testRetryLogsWarning(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->transportThrowsOnceThenReturns(
            new AgentErrorException('Unavailable', statusCode: 503),
            $fixture,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Strands request failed, retrying',
                $this->callback(function (array $context): bool {
                    return isset($context['attempt'])
                        && isset($context['max_retries'])
                        && isset($context['delay_ms'])
                        && isset($context['error'])
                        && $context['attempt'] === 1
                        && $context['max_retries'] === 1
                        && is_int($context['delay_ms'])
                        && $context['delay_ms'] >= 0
                        && $context['error'] === 'Unavailable';
                }),
            );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 1,
                retryDelayMs: 1,
            ),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that invoke with timeout seconds override.
     *
     * @return void
     */
    public function testInvokeWithTimeoutSecondsOverride(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                300,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $strandsClient->invoke(message: 'Test', timeoutSeconds: 300);
    }

    /**
     * Verifies that invoke timeout seconds rejects zero.
     *
     * @return void
     */
    public function testInvokeTimeoutSecondsRejectsZero(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1');

        $strandsClient->invoke(message: 'Test', timeoutSeconds: 0);
    }

    /**
     * Verifies that invoke timeout seconds null uses default.
     *
     * @return void
     */
    public function testInvokeTimeoutSecondsNullUsesDefault(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                60,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 60),
            transport: $transport,
        );

        $strandsClient->invoke(message: 'Test', timeoutSeconds: null);
    }

    /**
     * Verifies that config rejects retryable status code below 400.
     *
     * @return void
     */
    public function testConfigRejectsRetryableStatusCodeBelowLowerBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All retryableStatusCodes must be HTTP error codes (400-599), but got:');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [200]);
    }

    /**
     * Verifies that config rejects retryable status code above 599.
     *
     * @return void
     */
    public function testConfigRejectsRetryableStatusCodeAboveUpperBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All retryableStatusCodes must be HTTP error codes (400-599), but got:');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [600]);
    }

    /**
     * Verifies that config accepts valid retryable status codes.
     *
     * @return void
     */
    public function testConfigAcceptsValidRetryableStatusCodes(): void
    {
        $strandsConfig = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [429, 500, 502, 503, 504],
        );

        $this->assertSame([429, 500, 502, 503, 504], $strandsConfig->retryableStatusCodes);
    }

    /**
     * Verifies that config accepts boundary retryable status codes.
     *
     * @return void
     */
    public function testConfigAcceptsBoundaryRetryableStatusCodes(): void
    {
        $strandsConfig = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [400, 599],
        );

        $this->assertSame([400, 599], $strandsConfig->retryableStatusCodes);
    }

    /**
     * Verifies that config accepts empty retryable status codes.
     *
     * @return void
     */
    public function testConfigAcceptsEmptyRetryableStatusCodes(): void
    {
        $strandsConfig = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [],
        );

        $this->assertSame([], $strandsConfig->retryableStatusCodes);
    }

    /**
     * Verifies that invoke timeout seconds accepts boundary one.
     *
     * @return void
     */
    public function testInvokeTimeoutSecondsAcceptsBoundaryOne(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                1,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $strandsClient->invoke(message: 'Test', timeoutSeconds: 1);
    }

    /**
     * Verifies that invoke accepts agent input.
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
                // AgentInput with content blocks should produce an array message
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
     * Verifies that invoke accepts plain string with agent input signature.
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
                // Plain string should produce a string message
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
     * Verifies that invoke with text only agent input sends string.
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
                // AgentInput::text() without content blocks should serialize as plain string
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
     * Verifies that constructor throws when no transport can be detected.
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
     * Verifies that detect transport error message contains all parts.
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
        } catch (StrandsException $e) {
            $this->assertStringContainsString('No HTTP transport available', $e->getMessage());
            $this->assertStringContainsString('symfony/http-client', $e->getMessage());
            $this->assertStringContainsString('invoke + streaming support', $e->getMessage());
            $this->assertStringContainsString('PsrHttpTransport', $e->getMessage());
        } finally {
            unset($GLOBALS['__strands_class_exists_overrides']);
        }
    }

    /**
     * Verifies that middleware after response exception logs context.
     *
     * @return void
     */
    public function testMiddlewareAfterResponseExceptionLogsContext(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $requestMiddleware = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            /**
             * Return request headers and body from the middleware test stub.
             *
             * @param string $url Request URL being observed.
             * @param array<string, string> $headers Request headers supplied to the
             * middleware stub.
             * @param string $body Request body supplied to the middleware stub.
             * @return array{headers: array<string, string>, body: string} Headers and body
             * returned by the middleware stub.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                return ['headers' => $headers, 'body' => $body];
            }

            /**
             * Handle after-response middleware calls for the test stub.
             *
             * @param string $url Request URL being observed.
             * @param int $statusCode HTTP status code for the operation.
             * @param float $durationMs Operation duration in milliseconds.
             * @param \Throwable|null $error Optional transport or agent error raised by
             * the operation.
             * @return void
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

        // Should NOT throw — middleware exceptions are caught and logged
        $strandsClient->invoke(message: 'Test');
    }

    /**
     * Verifies that stream strips trailing slash from endpoint.
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
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $strandsClient->stream(message: 'Test', onEvent: function () {
        });
    }

    /**
     * Verifies that invoke rejects empty string.
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
     * Verifies that stream rejects empty string.
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
     * Verifies that invoke accepts interrupt response with empty text.
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

        // interruptResponse has empty text but content blocks — should not throw
        $input = \StrandsPhpClient\Context\AgentInput::interruptResponse('int-123', 'Approved');
        $response = $strandsClient->invoke(message: $input);

        $this->assertNotEmpty($response->text);
    }

    /**
     * Verifies that middleware runs before auth so signature covers modified body.
     *
     * @return void
     */
    public function testMiddlewareRunsBeforeAuthSoSignatureCoversModifiedBody(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        // Track the body and headers that auth receives
        $authReceivedBody = null;
        $authReceivedHeaders = null;

        $auth = $this->createStub(AuthStrategy::class);
        $auth->method('authenticate')
            ->willReturnCallback(function (array $headers, string $method, string $url, string $body) use (&$authReceivedBody, &$authReceivedHeaders): array {
                $authReceivedBody = $body;
                $authReceivedHeaders = $headers;
                $headers['Authorization'] = 'signed';

                return $headers;
            });

        // Middleware that modifies the body and adds a header
        $requestMiddleware = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            /**
             * Return request headers and body from the middleware test stub.
             *
             * @param string $url Request URL being observed.
             * @param array<string, string> $headers Request headers supplied to the
             * middleware stub.
             * @param string $body Request body supplied to the middleware stub.
             * @return array{headers: array<string, string>, body: string} Headers and body
             * returned by the middleware stub.
             */
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                $decoded = json_decode($body, true);
                $decoded['injected'] = true;
                $headers['X-Custom'] = 'from-middleware';

                return ['headers' => $headers, 'body' => json_encode($decoded)];
            }

            /**
             * Handle after-response middleware calls for the test stub.
             *
             * @param string $url Request URL being observed.
             * @param int $statusCode HTTP status code for the operation.
             * @param float $durationMs Operation duration in milliseconds.
             * @param \Throwable|null $error Optional transport or agent error raised by
             * the operation.
             * @return void
             */
            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
            }
        };

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', auth: $auth),
            transport: $transport,
            middleware: [$requestMiddleware],
        );

        $strandsClient->invoke(message: 'Test');

        // Auth must see the middleware-modified body
        $this->assertNotNull($authReceivedBody);
        $decoded = json_decode($authReceivedBody, true);
        $this->assertTrue($decoded['injected'], 'Auth must receive the body after middleware modification');

        // Auth must see the middleware-added header
        $this->assertSame('from-middleware', $authReceivedHeaders['X-Custom']);
    }
}

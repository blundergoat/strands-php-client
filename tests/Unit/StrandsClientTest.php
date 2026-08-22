<?php

declare(strict_types=1);

/**
 * Exercises synchronous requests and shared client setup from an application's perspective.
 *
 * It covers payloads, auth, retries, timeouts, middleware, transport detection, and logging.
 * Failures here mean a user action could reach the wrong endpoint or surface the wrong result.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * @param string $name Fixture name or DTO name under test.
     * @return array<string, mixed> Decoded fixture or processed configuration array.
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/' . $name;

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Loads captured fixture data for a realistic client-orchestration scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $name Fixture file name under tests/Fixtures/.
     * @return string Raw fixture contents.
     */
    private function loadSseFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * Supports the related client-orchestration scenario (transport throws once then returns).
     * Use it when request, retry, middleware, or logging flow needs this shared setup.
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
                // The first request models a transient failure the user never sees when the retry succeeds.
                if ($callCount === 1) {
                    throw $throwOnce;
                }

                return $thenReturn;
            });

        return $transport;
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the client-orchestration scenario must inspect requests or delivery order.
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
     * Checks the caller-visible fields shared by several client-orchestration scenarios.
     * Use it to keep repeated expectations consistent and readable.
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
     * Protects "invoke returns hydrated response" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke without session id" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke without context" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke sends correct payload" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke strips trailing slash" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke auth receives invoke url" so app requests keep predictable payloads and outcomes.
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
     * Protects "stream auth receives stream url" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke retries on retryable status code" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke retries on generic strands exception" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke does not retry non retryable status code" so app requests keep predictable payloads and outcomes.
     *
     * @return void
     * @throws AgentErrorException When the agent rejects the request without retry.
     */
    public function testInvokeDoesNotRetryNonRetryableStatusCode(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->expects($this->any())->method('post')
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
        } catch (AgentErrorException $exception) {
            // A bad user payload is not transient, so the same 400 must return after exactly one request.
            $this->assertSame(400, $exception->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 400');

            throw $exception;
        }
    }

    /**
     * Protects "invoke throws after max retries" so app requests keep predictable payloads and outcomes.
     *
     * @return void
     */
    public function testInvokeThrowsAfterMaxRetries(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')
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
     * Protects "invoke does not retry on unauthorized" so app requests keep predictable payloads and outcomes.
     *
     * @return void
     * @throws AgentErrorException When authentication fails and retries are skipped.
     */
    public function testInvokeDoesNotRetryOnUnauthorized(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->expects($this->any())->method('post')
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
        } catch (AgentErrorException $exception) {
            // Invalid credentials cannot recover through retry, so the app receives the original 401 after one request.
            $this->assertSame(401, $exception->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 401');

            throw $exception;
        }
    }

    /**
     * Protects "config accepts boundary max retries" so app requests keep predictable payloads and outcomes.
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
     * Protects "config rejects invalid field with identifying message" so app requests keep predictable payloads and outcomes.
     *
     * @param \Closure(): void $constructConfig Callback that constructs the
     *   invalid config; expected to throw InvalidArgumentException.
     * @param string $expectedMessageFragment Substring the exception message must contain.
     * @return void
     */
    #[DataProvider('invalidConfigConstructorProvider')]
    public function testConfigRejectsInvalidFieldWithIdentifyingMessage(\Closure $constructConfig, string $expectedMessageFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        $constructConfig();
    }

    /**
     * Supplies the input variants for the related client-orchestration scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: \Closure(): void, 1: string}> Invalid configuration cases that should fail before user calls run.
     */
    public static function invalidConfigConstructorProvider(): iterable
    {
        yield 'zero timeout' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 0),
            'timeout must be at least 1',
        ];
        yield 'negative connectTimeout' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', connectTimeout: -1),
            'connectTimeout must be at least 1',
        ];
        yield 'zero retryDelayMs' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', retryDelayMs: 0),
            'retryDelayMs must be at least 1',
        ];
        yield 'invalid endpoint URL' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'not a url'),
            'Invalid endpoint URL',
        ];
        yield 'maxRetries above upper bound' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 21),
            'maxRetries must be between 0 and 20',
        ];
        yield 'negative maxRetries' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: -1),
            'maxRetries must be between 0 and 20',
        ];
        yield 'retryableStatusCode below lower bound' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [200]),
            'All retryableStatusCodes must be HTTP error codes (400-599), but got:',
        ];
        yield 'retryableStatusCode above upper bound' => [
            static fn (): StrandsConfig => new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [600]),
            'All retryableStatusCodes must be HTTP error codes (400-599), but got:',
        ];
    }

    /**
     * Protects "config default values" so app requests keep predictable payloads and outcomes.
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
     * Protects "config accepts timeout boundary" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke logs request and response" so app requests keep predictable payloads and outcomes.
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
     * Protects "retry logs warning" so app requests keep predictable payloads and outcomes.
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

        $response = $strandsClient->invoke(message: 'Test');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Protects "invoke forwards resolved timeout to transport" so app requests keep predictable payloads and outcomes.
     *
     * @param int|null $requestedTimeoutSeconds Per-call timeout; null uses the configured app default.
     * @param int $configuredTimeout Default timeout set on StrandsConfig.
     * @param int $expectedForwardedTimeout Timeout value the transport.post() call must receive.
     * @return void
     */
    #[DataProvider('invokeTimeoutResolutionProvider')]
    public function testInvokeForwardsResolvedTimeoutToTransport(
        ?int $requestedTimeoutSeconds,
        int $configuredTimeout,
        int $expectedForwardedTimeout,
    ): void {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $expectedForwardedTimeout,
                10,
            )
            ->willReturn($fixture);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: $configuredTimeout),
            transport: $transport,
        );

        $response = $strandsClient->invoke(message: 'Test', timeoutSeconds: $requestedTimeoutSeconds);

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    /**
     * Supplies the input variants for the related client-orchestration scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: int|null, 1: int, 2: int}> Timeout cases that keep caller overrides predictable.
     */
    public static function invokeTimeoutResolutionProvider(): iterable
    {
        yield 'per-call override beats config default' => [300, 120, 300];
        yield 'null override falls back to config default' => [null, 60, 60];
        yield 'boundary value of 1 propagates' => [1, 120, 1];
    }

    /**
     * Protects "invoke timeout seconds rejects zero" so app requests keep predictable payloads and outcomes.
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
     * Protects "config preserves valid retryable status codes" so app requests keep predictable payloads and outcomes.
     *
     * @param list<int> $retryableStatusCodes Status codes passed to the constructor and expected unchanged.
     * @return void
     */
    #[DataProvider('validRetryableStatusCodesProvider')]
    public function testConfigPreservesValidRetryableStatusCodes(array $retryableStatusCodes): void
    {
        $strandsConfig = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: $retryableStatusCodes,
        );

        $this->assertSame($retryableStatusCodes, $strandsConfig->retryableStatusCodes);
    }

    /**
     * Supplies the input variants for the related client-orchestration scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: list<int>}> Retry status codes accepted for caller-controlled recovery.
     */
    public static function validRetryableStatusCodesProvider(): iterable
    {
        yield 'common retryable HTTP errors' => [[429, 500, 502, 503, 504]];
        yield 'boundary values (400 + 599)' => [[400, 599]];
        yield 'empty list' => [[]];
    }

    /**
     * Protects "invoke accepts agent input" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke accepts plain string with agent input signature" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke with text only agent input sends string" so app requests keep predictable payloads and outcomes.
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
     * Protects "constructor throws when no transport can be detected" so app requests keep predictable payloads and outcomes.
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
     * Protects "detect transport error message contains all parts" so app requests keep predictable payloads and outcomes.
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
     * Protects "middleware after response exception logs context" so app requests keep predictable payloads and outcomes.
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
             * @param string $url Request URL being observed.
             * @param array<string, string> $headers Request headers supplied to the
             * middleware stub.
             * @param string $body Request body supplied to the middleware stub.
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
             * @param string $url Request URL being observed.
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
     * Protects "stream strips trailing slash from endpoint" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke rejects empty string" so app requests keep predictable payloads and outcomes.
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
     * Protects "stream rejects empty string" so app requests keep predictable payloads and outcomes.
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
     * Protects "invoke accepts interrupt response with empty text" so app requests keep predictable payloads and outcomes.
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
     * Protects "middleware runs before auth so signature covers modified body" so app requests keep predictable payloads and outcomes.
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
             * @param string $url Request URL being observed.
             * @param array<string, string> $headers Request headers supplied to the
             * middleware stub.
             * @param string $body Request body supplied to the middleware stub.
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
             * @param string $url Request URL being observed.
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

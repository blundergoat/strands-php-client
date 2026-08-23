<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies retries, timeouts, and configuration validation produce predictable caller outcomes.
 *
 * Use these tests when changing retry policy, timeout resolution, or transport selection.
 * They protect transient recovery while returning permanent request and authentication failures promptly.
 */
class StrandsClientRetryAndConfigTest extends TestCase
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
     * Builds a transport that fails once and returns a successful agent payload on retry.
     * Use it to model a transient failure the caller never sees.
     *
     * @param \Throwable $throwOnce Failure raised on the first post(); never null.
     * @param array<string, mixed> $thenReturn Later response fields; empty models an agent response with no fields.
     * @return HttpTransport Retry transport with the failure-then-success sequence; never null.
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
     * Verifies invoke() retries on retryable status code so callers get the configured request behavior.
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
     * Verifies invoke() retries a generic StrandsException so callers get the configured recovery behavior.
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
     * Verifies invoke() does not retry non retryable status code so callers get the configured request behavior.
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
     * Verifies invoke() throws after max retries so callers get the configured request behavior.
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
     * Verifies invoke() does not retry on unauthorized so callers get the configured request behavior.
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
     * Verifies configuration accepts the maximum retry boundary so callers get the configured request behavior.
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
     * Verifies configuration errors identify the invalid field so callers get the configured request behavior.
     *
     * @param \Closure(): void $constructConfig Non-null callback that constructs the
     *   invalid config; expected to throw InvalidArgumentException.
     * @param string $expectedMessageFragment Non-empty guidance the exception message must contain.
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
     * Lists invalid client settings and the configuration guidance callers should receive.
     * An empty provider would leave one startup validation path unverified.
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
     * Verifies configuration supplies documented defaults so callers get the configured request behavior.
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
     * Verifies configuration accepts the timeout boundary so callers get the configured request behavior.
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
     * Verifies retry logs warning so callers get the configured request behavior.
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
     * Verifies invoke() forwards resolved timeout to transport so callers get the configured request behavior.
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
     * Lists per-call and configured timeouts with the value sent to the transport.
     * An empty provider would leave timeout precedence unverified.
     *
     * @return iterable<string, array{0: int|null, 1: int, 2: int}> Timeout cases; null selects the configured default.
     *   The provider is never empty.
     */
    public static function invokeTimeoutResolutionProvider(): iterable
    {
        yield 'per-call override beats config default' => [300, 120, 300];
        yield 'null override falls back to config default' => [null, 60, 60];
        yield 'boundary value of 1 propagates' => [1, 120, 1];
    }

    /**
     * Verifies invoke() timeout seconds rejects zero so callers get the configured request behavior.
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
     * Verifies configuration preserves each valid retryable status code so callers get the configured request behavior.
     *
     * @param list<int> $retryableStatusCodes Caller-selected codes; empty disables status-based retries.
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
     * Lists retry status-code sets callers may configure, including an empty opt-out list.
     * An empty provider would leave retry customization unverified.
     *
     * @return iterable<string, array{0: list<int>}> Retry-code cases; never empty, with one empty-list opt-out case.
     */
    public static function validRetryableStatusCodesProvider(): iterable
    {
        yield 'common retryable HTTP errors' => [[429, 500, 502, 503, 504]];
        yield 'boundary values (400 + 599)' => [[400, 599]];
        yield 'empty list' => [[]];
    }
}

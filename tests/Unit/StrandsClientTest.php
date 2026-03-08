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
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/' . $name;

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createMockTransport(array $response): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('post')->willReturn($response);

        return $mock;
    }

    public function testInvokeReturnsHydratedResponse(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: new NullAuth(),
            ),
            transport: $transport,
        );

        $response = $client->invoke(
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

    public function testInvokeWithoutSessionId(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $fixture['session_id'] = null;
        $transport = $this->createMockTransport($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $client->invoke(message: 'What is 2+2?');

        $this->assertNull($response->sessionId);
    }

    public function testInvokeWithoutContext(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $client->invoke(message: 'Hello');

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->invoke(
            message: 'Test message',
            context: AgentContext::create()->withMetadata('persona', 'skeptic'),
            sessionId: 'sess-123',
        );
    }

    public function testInvokeStripsTrailingSlash(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with('http://localhost:8081/invoke', $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $client->invoke(message: 'Test');
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $client->invoke(message: 'Test');
    }

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

        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $client->stream(message: 'Test', onEvent: function () {
        });
    }

    public function testInvokeRetriesOnRetryableStatusCode(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount, $fixture) {
                $callCount++;
                if ($callCount === 1) {
                    throw new AgentErrorException('Service unavailable', statusCode: 503);
                }

                return $fixture;
            });

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $response = $client->invoke(message: 'Test');

        $this->assertStringContainsString('BLUF', $response->text);
        $this->assertSame(2, $callCount);
    }

    public function testInvokeRetriesOnGenericStrandsException(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount, $fixture) {
                $callCount++;
                if ($callCount === 1) {
                    throw new StrandsException('Network error');
                }

                return $fixture;
            });

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $response = $client->invoke(message: 'Test');

        $this->assertStringContainsString('BLUF', $response->text);
        $this->assertSame(2, $callCount);
    }

    public function testInvokeDoesNotRetryNonRetryableStatusCode(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new AgentErrorException('Bad request', statusCode: 400);
            });

        $client = new StrandsClient(
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
            $client->invoke(message: 'Test');
        } catch (AgentErrorException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 400');

            throw $e;
        }
    }

    public function testInvokeThrowsAfterMaxRetries(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')
            ->willThrowException(new AgentErrorException('Service unavailable', statusCode: 503));

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $this->expectException(AgentErrorException::class);
        $this->expectExceptionMessage('Service unavailable');

        $client->invoke(message: 'Test');
    }

    public function testInvokeDoesNotRetryOn401(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new AgentErrorException('Unauthorized', statusCode: 401);
            });

        $client = new StrandsClient(
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
            $client->invoke(message: 'Test');
        } catch (AgentErrorException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 401');

            throw $e;
        }
    }

    public function testConfigAcceptsBoundaryMaxRetries(): void
    {
        $configZero = new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 0);
        $this->assertSame(0, $configZero->maxRetries);

        $configMax = new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 20);
        $this->assertSame(20, $configMax->maxRetries);
    }

    public function testConfigRejectsZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 0);
    }

    public function testConfigRejectsNegativeConnectTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('connectTimeout must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', connectTimeout: -1);
    }

    public function testConfigRejectsZeroRetryDelayMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryDelayMs must be at least 1');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryDelayMs: 0);
    }

    public function testConfigRejectsInvalidEndpointUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid endpoint URL');

        new StrandsConfig(endpoint: 'not a url');
    }

    public function testConfigRejectsMaxRetriesAbove20(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be between 0 and 20');

        new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: 21);
    }

    public function testConfigRejectsNegativeMaxRetries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries must be between 0 and 20');

        new StrandsConfig(endpoint: 'http://localhost:8081', maxRetries: -1);
    }

    public function testConfigDefaultValues(): void
    {
        $config = new StrandsConfig(endpoint: 'http://localhost:8081');

        $this->assertSame(120, $config->timeout);
        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame(0, $config->maxRetries);
        $this->assertSame(500, $config->retryDelayMs);
        $this->assertSame([429, 502, 503, 504], $config->retryableStatusCodes);
    }

    public function testConfigAcceptsTimeoutBoundary(): void
    {
        $config = new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 1);
        $this->assertSame(1, $config->timeout);

        $config2 = new StrandsConfig(endpoint: 'http://localhost:8081', connectTimeout: 1);
        $this->assertSame(1, $config2->connectTimeout);

        $config3 = new StrandsConfig(endpoint: 'http://localhost:8081', retryDelayMs: 1);
        $this->assertSame(1, $config3->retryDelayMs);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->invoke(message: 'Test', sessionId: 'sess-log');

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

    public function testRetryLogsWarning(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount, $fixture) {
                $callCount++;
                if ($callCount === 1) {
                    throw new AgentErrorException('Unavailable', statusCode: 503);
                }

                return $fixture;
            });

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

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 1,
                retryDelayMs: 1,
            ),
            transport: $transport,
            logger: $logger,
        );

        $client->invoke(message: 'Test');
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->invoke(message: 'Test', timeoutSeconds: 300);
    }

    public function testInvokeTimeoutSecondsRejectsZero(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1');

        $client->invoke(message: 'Test', timeoutSeconds: 0);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 60),
            transport: $transport,
        );

        $client->invoke(message: 'Test', timeoutSeconds: null);
    }

    public function testConfigRejectsRetryableStatusCodeBelow400(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All retryableStatusCodes must be HTTP error codes (400-599), but got:');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [200]);
    }

    public function testConfigRejectsRetryableStatusCodeAbove599(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All retryableStatusCodes must be HTTP error codes (400-599), but got:');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [600]);
    }

    public function testConfigAcceptsValidRetryableStatusCodes(): void
    {
        $config = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [429, 500, 502, 503, 504],
        );

        $this->assertSame([429, 500, 502, 503, 504], $config->retryableStatusCodes);
    }

    public function testConfigAcceptsBoundaryRetryableStatusCodes(): void
    {
        $config = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [400, 599],
        );

        $this->assertSame([400, 599], $config->retryableStatusCodes);
    }

    public function testConfigAcceptsEmptyRetryableStatusCodes(): void
    {
        $config = new StrandsConfig(
            endpoint: 'http://localhost:8081',
            retryableStatusCodes: [],
        );

        $this->assertSame([], $config->retryableStatusCodes);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->invoke(message: 'Test', timeoutSeconds: 1);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text("What's in this image?")
            ->withImage('base64cat', 'image/jpeg');

        $response = $client->invoke($input);

        $this->assertSame('I see a cat', $response->text);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $response = $client->invoke('Hello');

        $this->assertSame('Hi', $response->text);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $input = AgentInput::text('Simple text');
        $response = $client->invoke($input);

        $this->assertSame('OK', $response->text);
    }

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

    public function testMiddlewareAfterResponseExceptionLogsContext(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $mw = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                return ['headers' => $headers, 'body' => $body];
            }

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
                $this->callback(function (array $context) use ($mw): bool {
                    return isset($context['middleware'])
                        && isset($context['error'])
                        && $context['middleware'] === $mw::class
                        && $context['error'] === 'Middleware boom';
                }),
            );

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
            middleware: [$mw],
        );

        // Should NOT throw — middleware exceptions are caught and logged
        $client->invoke(message: 'Test');
    }

    public function testStreamStripsTrailingSlashFromEndpoint(): void
    {
        $sseData = file_get_contents(__DIR__ . '/../Fixtures/sse-simple-text.txt');

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('stream')
            ->with('http://localhost:8081/stream', $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData) {
                $onChunk($sseData);
            });

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $client->stream(message: 'Test', onEvent: function () {
        });
    }

    public function testInvokeRejectsEmptyString(): void
    {
        $transport = $this->createMockTransport([]);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $client->invoke(message: '');
    }

    public function testStreamRejectsEmptyString(): void
    {
        $transport = $this->createMockTransport([]);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $client->stream(message: '', onEvent: function () {
        });
    }

    public function testInvokeAcceptsInterruptResponseWithEmptyText(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');
        $transport = $this->createMockTransport($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        // interruptResponse has empty text but content blocks — should not throw
        $input = \StrandsPhpClient\Context\AgentInput::interruptResponse('int-123', 'Approved');
        $response = $client->invoke(message: $input);

        $this->assertNotEmpty($response->text);
    }

    public function testMiddlewareRunsBeforeAuthSoSignatureCoversModifiedBody(): void
    {
        $fixture = $this->loadFixture('invoke-analyst-response.json');

        // Track the body and headers that auth receives
        $authReceivedBody = null;
        $authReceivedHeaders = null;

        $auth = $this->createMock(AuthStrategy::class);
        $auth->method('authenticate')
            ->willReturnCallback(function (array $headers, string $method, string $url, string $body) use (&$authReceivedBody, &$authReceivedHeaders): array {
                $authReceivedBody = $body;
                $authReceivedHeaders = $headers;
                $headers['Authorization'] = 'signed';

                return $headers;
            });

        // Middleware that modifies the body and adds a header
        $mw = new class () implements \StrandsPhpClient\Http\RequestMiddleware {
            public function beforeRequest(string $url, array $headers, string $body): array
            {
                $decoded = json_decode($body, true);
                $decoded['injected'] = true;
                $headers['X-Custom'] = 'from-middleware';

                return ['headers' => $headers, 'body' => json_encode($decoded)];
            }

            public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
            {
            }
        };

        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')->willReturn($fixture);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', auth: $auth),
            transport: $transport,
            middleware: [$mw],
        );

        $client->invoke(message: 'Test');

        // Auth must see the middleware-modified body
        $this->assertNotNull($authReceivedBody);
        $decoded = json_decode($authReceivedBody, true);
        $this->assertTrue($decoded['injected'], 'Auth must receive the body after middleware modification');

        // Auth must see the middleware-added header
        $this->assertSame('from-middleware', $authReceivedHeaders['X-Custom']);
    }
}

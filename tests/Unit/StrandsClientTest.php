<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Auth\NullAuth;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
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
        $logger->expects($this->exactly(2))
            ->method('debug');

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->invoke(message: 'Test');
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
            ->method('warning');

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
        $this->expectExceptionMessage('retryableStatusCodes must contain HTTP error codes (400-599)');

        new StrandsConfig(endpoint: 'http://localhost:8081', retryableStatusCodes: [200]);
    }

    public function testConfigRejectsRetryableStatusCodeAbove599(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryableStatusCodes must contain HTTP error codes (400-599)');

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
}

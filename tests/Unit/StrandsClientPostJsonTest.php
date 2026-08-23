<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies custom JSON requests preserve paths and payloads while sharing authentication, retries, timeouts, and logging.
 *
 * Use these tests when changing StrandsClient::postJson() or shared request orchestration.
 * They protect domain-specific endpoints that return raw response objects to the calling app.
 */
class StrandsClientPostJsonTest extends TestCase
{
    /**
     * Builds a transport that returns one controlled custom-endpoint response.
     * Use it when the scenario needs response data without inspecting transport calls.
     *
     * @param array<string, mixed> $responseData Parsed response map; empty models a valid empty JSON object.
     * @return HttpTransport Mock transport returning the supplied custom response.
     */
    private function mockTransportReturning(array $responseData): HttpTransport
    {
        $mockTransport = $this->createMock(HttpTransport::class);
        $mockTransport->method('post')->willReturn($responseData);

        return $mockTransport;
    }

    /**
     * Builds a transport whose post() throws once and then returns the given payload on later calls.
     * Keeps retry-counting state out of test bodies so each retry test reads linearly.
     *
     * @param \Throwable $throwOnce Exception thrown by the first call to post().
     * @param array<string, mixed> $thenReturn Later response fields; empty models an endpoint with no response fields.
     * @return HttpTransport Mocked transport with the throw-then-return sequence wired up.
     */
    private function transportThrowsOnceThenReturns(\Throwable $throwOnce, array $thenReturn): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount, $throwOnce, $thenReturn): array {
                $callCount++;
                // The first app request models a transient failure; a retry receives the successful payload.
                if ($callCount === 1) {
                    throw $throwOnce;
                }

                return $thenReturn;
            });

        return $transport;
    }

    /**
     * Confirms postJson() sends correct URL so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonSendsCorrectUrl(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8081/file-summarise',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['summary' => 'test']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame(['summary' => 'test'], $result);
    }

    /**
     * Confirms postJson() sends correct payload so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonSendsCorrectPayload(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(fn (array $headers) => $headers['Content-Type'] === 'application/json'
                    && $headers['Accept'] === 'application/json'),
                $this->callback(function (string $body) {
                    $responseData = json_decode($body, true);

                    return $responseData['file_base64'] === 'abc'
                        && $responseData['file_name'] === 'test.pdf'
                        && $responseData['mime_type'] === 'application/pdf';
                }),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['summary' => 'test']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-summarise', [
            'file_base64' => 'abc',
            'file_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->assertSame(['summary' => 'test'], $result);
    }

    /**
     * Confirms postJson() applies auth so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonAppliesAuth(): void
    {
        $auth = $this->createMock(AuthStrategy::class);
        $auth->expects($this->once())
            ->method('authenticate')
            ->with(
                $this->anything(),
                'POST',
                'http://localhost:8081/file-summarise',
                $this->anything(),
            )
            ->willReturnArgument(0);

        $transport = $this->mockTransportReturning(['summary' => 'test']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame(['summary' => 'test'], $result);
    }

    /**
     * Confirms postJson() returns decoded array so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonReturnsDecodedArray(): void
    {
        $expected = [
            'summary' => 'A detailed summary',
            'model' => 'claude-3',
            'verification' => ['score' => 95, 'verdict' => 'excellent'],
        ];

        $transport = $this->mockTransportReturning($expected);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame($expected, $result);
    }

    /**
     * Confirms postJson() retries on transient error so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonRetriesOnTransientError(): void
    {
        $transport = $this->transportThrowsOnceThenReturns(
            new AgentErrorException('Service unavailable', statusCode: 503),
            ['summary' => 'test'],
        );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame('test', $result['summary']);
    }

    /**
     * Confirms postJson() does not retry on 400 so callers keep the documented result.
     *
     * @return void
     * @throws AgentErrorException When the custom endpoint rejects the request.
     */
    public function testPostJsonDoesNotRetryOnBadRequest(): void
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

        try {
            $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $agentErrorException) {
            // For example, invalid form input is a permanent 400; return it immediately instead of making the user wait through retries.
            $this->assertSame(400, $agentErrorException->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 400');
        }
    }

    /**
     * Confirms postJson() throws on encoding failure so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonThrowsOnEncodingFailure(): void
    {
        $transport = $this->mockTransportReturning([]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        try {
            $strandsClient->postJson('/file-summarise', ['bad_value' => NAN]);
            $this->fail('Expected StrandsException');
        } catch (StrandsException $encodingException) {
            // For example, an app can pass a non-finite metric; the UI needs the client context and original JSON failure.
            $this->assertStringContainsString('Failed to encode request payload', $encodingException->getMessage());
            $this->assertStringContainsString('Inf and NaN', $encodingException->getMessage());
        }
    }

    /**
     * Confirms postJson() logs debug so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonLogsDebug(): void
    {
        $transport = $this->mockTransportReturning(['summary' => 'test']);

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

        $strandsClient->postJson('/file-summarise', ['file_base64' => 'abc']);

        // Request log must include url and path
        $this->assertSame('Strands postJson request', $debugCalls[0]['message']);
        $this->assertArrayHasKey('url', $debugCalls[0]['context']);
        $this->assertArrayHasKey('path', $debugCalls[0]['context']);

        // Response log must include url
        $this->assertSame('Strands postJson response', $debugCalls[1]['message']);
        $this->assertArrayHasKey('url', $debugCalls[1]['context']);
    }

    /**
     * Confirms postJson() uses config timeout by default so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonUsesConfigTimeoutByDefault(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                120,
                10,
            )
            ->willReturn(['ok' => true]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/test', ['data' => 'test']);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * Confirms postJson() uses per request timeout so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonUsesPerRequestTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                30,
                10,
            )
            ->willReturn(['ok' => true]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120, connectTimeout: 10),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/file-metadata', ['data' => 'test'], timeout: 30);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * Confirms postJson() handles empty path so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonHandlesEmptyPath(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8081',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['ok' => true]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $strandsClient->postJson('', ['data' => 'test']);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * Confirms postJson() rejects zero timeout so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonRejectsZeroTimeout(): void
    {
        $transport = $this->mockTransportReturning([]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $strandsClient->postJson('/test', ['data' => 'test'], timeout: 0);
    }

    /**
     * Confirms postJson() rejects negative timeout so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonRejectsNegativeTimeout(): void
    {
        $transport = $this->mockTransportReturning([]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be at least 1');

        $strandsClient->postJson('/test', ['data' => 'test'], timeout: -10);
    }

    /**
     * Confirms postJson() null timeout uses default so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonNullTimeoutUsesDefault(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                120,
                $this->anything(),
            )
            ->willReturn(['ok' => true]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081', timeout: 120),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/test', ['data' => 'test'], timeout: null);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * Confirms postJson() accepts boundary one timeout so callers keep the documented result.
     *
     * @return void
     */
    public function testPostJsonAcceptsBoundaryOneTimeout(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                1,
                $this->anything(),
            )
            ->willReturn(['ok' => true]);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $strandsClient->postJson('/test', ['data' => 'test'], timeout: 1);

        $this->assertSame(['ok' => true], $result);
    }
}

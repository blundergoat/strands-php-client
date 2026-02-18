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

class StrandsClientPostJsonTest extends TestCase
{
    private function createMockTransport(array $response): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('post')->willReturn($response);

        return $mock;
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081/'),
            transport: $transport,
        );

        $client->postJson('/file-summarise', ['file_base64' => 'abc']);
    }

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
                    $data = json_decode($body, true);

                    return $data['file_base64'] === 'abc'
                        && $data['file_name'] === 'test.pdf'
                        && $data['mime_type'] === 'application/pdf';
                }),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['summary' => 'test']);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $client->postJson('/file-summarise', [
            'file_base64' => 'abc',
            'file_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

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

        $transport = $this->createMockTransport(['summary' => 'test']);

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                auth: $auth,
            ),
            transport: $transport,
        );

        $client->postJson('/file-summarise', ['file_base64' => 'abc']);
    }

    public function testPostJsonReturnsDecodedArray(): void
    {
        $expected = [
            'summary' => 'A detailed summary',
            'model' => 'claude-3',
            'verification' => ['score' => 95, 'verdict' => 'excellent'],
        ];

        $transport = $this->createMockTransport($expected);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame($expected, $result);
    }

    public function testPostJsonRetriesOnTransientError(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $callCount = 0;
        $transport->method('post')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new AgentErrorException('Service unavailable', statusCode: 503);
                }

                return ['summary' => 'test'];
            });

        $client = new StrandsClient(
            config: new StrandsConfig(
                endpoint: 'http://localhost:8081',
                maxRetries: 2,
                retryDelayMs: 1,
            ),
            transport: $transport,
        );

        $result = $client->postJson('/file-summarise', ['file_base64' => 'abc']);

        $this->assertSame('test', $result['summary']);
        $this->assertSame(2, $callCount);
    }

    public function testPostJsonDoesNotRetryOn400(): void
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

        try {
            $client->postJson('/file-summarise', ['file_base64' => 'abc']);
            $this->fail('Expected AgentErrorException');
        } catch (AgentErrorException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame(1, $callCount, 'Should not retry on 400');
        }
    }

    public function testPostJsonThrowsOnEncodingFailure(): void
    {
        $transport = $this->createMockTransport([]);

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $this->expectException(StrandsException::class);
        $this->expectExceptionMessage('Failed to encode request payload');

        $client->postJson('/file-summarise', ['bad_value' => NAN]);
    }

    public function testPostJsonLogsDebug(): void
    {
        $transport = $this->createMockTransport(['summary' => 'test']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug');

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $client->postJson('/file-summarise', ['file_base64' => 'abc']);
    }

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

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $result = $client->postJson('', ['data' => 'test']);

        $this->assertSame(['ok' => true], $result);
    }
}

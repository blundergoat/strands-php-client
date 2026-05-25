<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

final class ResponseObserverTest extends TestCase
{
    /**
     * Verifies that invoke notifies response observer with parsed response.
     *
     * @return void
     */
    public function testInvokeNotifiesResponseObserverWithParsedResponse(): void
    {
        $observer = $this->createMock(ResponseObserver::class);
        $observer->expects($this->once())
            ->method('afterInvoke')
            ->with(
                'http://localhost:8081/invoke',
                $this->callback(fn (AgentResponse $response): bool => $response->text === 'ok'),
                $this->greaterThan(0),
            );

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(['text' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $strandsClient->invoke('hello');
    }

    /**
     * Verifies that stream notifies response observer with parsed result.
     *
     * @return void
     */
    public function testStreamNotifiesResponseObserverWithParsedResult(): void
    {
        $observer = $this->createMock(ResponseObserver::class);
        $observer->expects($this->once())
            ->method('afterStream')
            ->with(
                'http://localhost:8081/stream',
                $this->callback(fn (StreamResult $result): bool => $result->text === 'done' && $result->totalEvents === 2),
                $this->greaterThan(0),
            );

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void {
                $onChunk->__invoke("data: {\"type\":\"text\",\"content\":\"done\"}\n\n"
                    . "data: {\"type\":\"complete\",\"text\":\"done\",\"usage\":{},\"tools_used\":[]}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $strandsClient->stream('hello', static function (): void {
        });
    }

    /**
     * Verifies that post JSON notifies response observer with raw response.
     *
     * @return void
     */
    public function testPostJsonNotifiesResponseObserverWithRawResponse(): void
    {
        $observer = $this->createMock(ResponseObserver::class);
        $observer->expects($this->once())
            ->method('afterPostJson')
            ->with(
                'http://localhost:8081/custom',
                ['status' => 'ok'],
                $this->greaterThan(0),
            );

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(['status' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $strandsClient->postJson('/custom', ['message' => 'hello']);
    }

    /**
     * Verifies that stream SSE notifies response observer with sanitized summary.
     *
     * @return void
     */
    public function testStreamSseNotifiesResponseObserverWithSanitizedSummary(): void
    {
        $observer = $this->createMock(ResponseObserver::class);
        $observer->expects($this->once())
            ->method('afterStreamSse')
            ->with(
                'http://localhost:8081/custom-stream',
                $this->callback(fn (StreamSseSummary $summary): bool => $summary->totalEvents === 2
                    && $summary->textEvents === 1
                    && $summary->cancelled === false
                    && $summary->terminalType === 'complete'
                    && $summary->usage?->inputTokens === 11
                    && $summary->stopReason === 'end_turn'),
                $this->greaterThan(0),
            );

        $transport = $this->createStub(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void {
                $onChunk->__invoke("data: {\"type\":\"text\",\"content\":\"secret response text\"}\n\n"
                    . "data: {\"type\":\"complete\",\"usage\":{\"input_tokens\":11,\"output_tokens\":3},\"stop_reason\":\"end_turn\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $strandsClient->streamSse('/custom-stream', ['message' => 'hello'], static function (): void {
        });
    }
}

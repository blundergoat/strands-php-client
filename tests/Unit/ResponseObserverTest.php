<?php

declare(strict_types=1);

/**
 * Tests caller-visible Response Observer behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Verifies Response Observer behavior that application users rely on.
 */
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

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn(['text' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $response = $strandsClient->invoke('hello');

        $this->assertSame('ok', $response->text);
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

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void {
                $onChunk->__invoke("data: {\"type\":\"text\",\"content\":\"done\"}\n\n"
                    . "data: {\"type\":\"complete\",\"text\":\"done\",\"usage\":{},\"tools_used\":[]}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $streamResult = $strandsClient->stream('hello', static function (): void {
        });

        $this->assertSame('done', $streamResult->text);
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

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('post')->willReturn(['status' => 'ok']);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $result = $strandsClient->postJson('/custom', ['message' => 'hello']);

        $this->assertSame(['status' => 'ok'], $result);
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

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->any())->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void {
                $onChunk->__invoke("data: {\"type\":\"text\",\"content\":\"secret response text\"}\n\n"
                    . "data: {\"type\":\"complete\",\"usage\":{\"input_tokens\":11,\"output_tokens\":3},\"stop_reason\":\"end_turn\"}\n\n");
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            responseObservers: [$observer],
        );

        $events = [];
        $strandsClient->streamSse('/custom-stream', ['message' => 'hello'], static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(2, $events, 'onEvent must receive every parsed SSE event delivered to the observer');
    }
}

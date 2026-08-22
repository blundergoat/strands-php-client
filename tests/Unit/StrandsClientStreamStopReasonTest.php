<?php

declare(strict_types=1);

/**
 * Exercises a streamed stop reason added after the public 1.4 enum was released.
 *
 * It mirrors an application receiving a newer wrapper value while remaining on client 1.x.
 * Failures here mean the UI could lose the reason a streamed answer stopped.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies additive stop reasons remain visible without changing the exhaustive 1.x enum.
 *
 * It protects apps that switch over the seven legacy enum cases while logging or displaying newer raw values.
 * Use this scenario when changing terminal-event hydration or stop-reason compatibility.
 */
final class StrandsClientStreamStopReasonTest extends TestCase
{
    /**
     * Protects "stream preserves unknown raw stop reason" so apps can show newer outcomes without breaking 1.x enum switches.
     *
     * @return void
     */
    public function testStreamPreservesUnknownRawStopReason(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ): void {
                $onChunk->__invoke(
                    'data: {"type": "complete", "text": "Done", "usage": {}, '
                    . '"tools_used": [], "stop_reason": "limit_turns"}' . "\n\n",
                );
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: static function (): void {
            },
        );

        $this->assertNull($streamResult->stopReason);
        $this->assertSame('limit_turns', $streamResult->rawStopReason);
    }
}

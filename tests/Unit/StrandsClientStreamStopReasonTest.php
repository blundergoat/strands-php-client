<?php

declare(strict_types=1);

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
     * Verifies stream() preserves unknown raw stop reason, keeping newer raw outcomes visible without breaking exhaustive 1.x enum switches.
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

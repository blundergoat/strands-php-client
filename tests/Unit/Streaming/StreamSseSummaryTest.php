<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Verifies raw SSE observation starts from neutral counts, timing, cancellation, terminal-event, and error values.
 *
 * Use this test when adding summary fields or changing observer defaults.
 * It protects app telemetry from reporting activity that never occurred.
 */
final class StreamSseSummaryTest extends TestCase
{
    /**
     * Confirms a stream with no observed events has neutral defaults so apps receive reliable live updates.
     *
     * @return void
     */
    public function testConstructorUsesNeutralDefaults(): void
    {
        $summary = new StreamSseSummary();

        $this->assertSame(0, $summary->totalEvents);
        $this->assertSame(0, $summary->textEvents);
        $this->assertFalse($summary->cancelled);
        $this->assertNull($summary->terminalType);
        $this->assertNull($summary->usage);
        $this->assertNull($summary->stopReason);
    }
}

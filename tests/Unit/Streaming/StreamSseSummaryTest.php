<?php

declare(strict_types=1);

/**
 * Tests the safe defaults used for raw SSE telemetry summaries.
 */

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Verifies StreamSseSummary behavior that application users rely on.
 */
final class StreamSseSummaryTest extends TestCase
{
    /**
     * Verifies that a stream with no observed events has neutral defaults.
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

<?php

declare(strict_types=1);

/**
 * Exercises the safe defaults used for raw SSE telemetry summaries.
 *
 * Use this file when changing the metrics reported for a live answer.
 * It protects dashboards from missing or misleading stream state.
 */

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Exercises StreamSseSummary through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
final class StreamSseSummaryTest extends TestCase
{
    /**
     * Confirms a stream with no observed events has neutral defaults so live answer updates and completion state stay reliable.
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

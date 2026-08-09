<?php

declare(strict_types=1);

/**
 * Tests defensive parsing and rounding of usage counters.
 */

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Usage;

/**
 * Verifies Usage behavior that application users rely on.
 */
final class UsageTest extends TestCase
{
    /**
     * Verifies that omitted usage fields retain their documented zero defaults.
     *
     * @return void
     */
    public function testFromArrayDefaultsEveryUsageFieldToZero(): void
    {
        $usage = Usage::fromArray([]);

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(0, $usage->cacheReadInputTokens);
        $this->assertSame(0, $usage->cacheWriteInputTokens);
        $this->assertSame(0, $usage->latencyMs);
        $this->assertSame(0, $usage->timeToFirstByteMs);
        $this->assertSame(0, $usage->totalTokens);
        $this->assertSame(0, $usage->totalTokens());
    }

    /**
     * Verifies that fractional token counts round to the nearest integer.
     *
     * @return void
     */
    public function testFromArrayRoundsFractionalTokenCounts(): void
    {
        $usage = Usage::fromArray([
            'input_tokens' => 1.6,
            'output_tokens' => 1.4,
        ]);

        $this->assertSame(2, $usage->inputTokens);
        $this->assertSame(1, $usage->outputTokens);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Usage;

/**
 * Verifies usage hydration preserves the public 1.x integer contract.
 *
 * It protects token and timing readouts from missing, fractional, non-finite, or overflowing wire values.
 * Use these scenarios whenever the shared Usage parser or its public properties change.
 */
final class UsageTest extends TestCase
{
    /**
     * Verifies Usage::fromArray() defaults every usage field to zero so callers never display or log corrupt metrics.
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
     * Verifies Usage::fromArray() rounds fractional token counts so callers never display or log corrupt metrics.
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

    /**
     * Verifies Usage::fromArray() rounds fractional timing values to integers so callers never display or log corrupt metrics.
     *
     * @return void
     */
    public function testFromArrayRoundsFractionalTimingValuesToIntegers(): void
    {
        $expectedLatencyMs = 843;
        $expectedTimeToFirstByteMs = 210;
        $usage = Usage::fromArray([
            'latency_ms' => 842.5,
            'time_to_first_byte_ms' => '210.1',
        ]);

        $this->assertSame($expectedLatencyMs, $usage->latencyMs);
        $this->assertSame($expectedTimeToFirstByteMs, $usage->timeToFirstByteMs);
    }

    /**
     * Verifies Usage::fromArray() rejects unsafe numeric values so callers never display or log corrupt metrics.
     *
     * @param int|float|string $wireValue Non-finite or out-of-range value supplied by a wrapper.
     * @return void The assertion protects cost and latency displays from corrupt numeric input.
     */
    #[DataProvider('unsafeNumericUsageProvider')]
    public function testFromArrayRejectsUnsafeNumericValues(int|float|string $wireValue): void
    {
        $usage = Usage::fromArray(['input_tokens' => $wireValue]);

        $this->assertSame(0, $usage->inputTokens);
    }

    /**
     * Lists unsafe wire numbers that must become zero before callers display or log them.
     * An empty provider would leave corrupt usage values unverified.
     *
     * @return iterable<string, array{0: int|float|string}> Non-empty unsafe-number cases and readable labels.
     */
    public static function unsafeNumericUsageProvider(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'finite value beyond integer range' => [1.0e30];
        yield 'numeric string beyond float range' => ['1e309'];
    }

    /**
     * Verifies Usage::fromArray() preserves an exact integer string at the platform boundary so callers never display or log corrupt metrics.
     *
     * @return void The assertion protects wrappers that encode large integer counters as strings.
     */
    public function testFromArrayPreservesExactIntegerStringAtPlatformBoundary(): void
    {
        $usage = Usage::fromArray(['input_tokens' => (string) PHP_INT_MAX]);

        $this->assertSame(PHP_INT_MAX, $usage->inputTokens);
    }

    /**
     * Verifies the Usage constructor rejects fractional timing values to keep integer contract so callers never display or log corrupt metrics.
     *
     * @param \Closure(): Usage $constructUsage Non-null factory for the usage object under test.
     * @param string $expectedParameter Non-empty name of the rejected constructor parameter.
     * @return void
     */
    #[DataProvider('fractionalTimingConstructorProvider')]
    public function testConstructorRejectsFractionalTimingValuesToKeepIntegerContract(
        \Closure $constructUsage,
        string $expectedParameter,
    ): void {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage($expectedParameter);

        $constructUsage();
    }

    /**
     * Lists fractional timing arguments the typed constructor must reject.
     * An empty provider would leave one caller-facing timing field unverified.
     *
     * @return iterable<string, array{0: \Closure(): Usage, 1: string}> Non-empty constructor and parameter-name cases.
     */
    public static function fractionalTimingConstructorProvider(): iterable
    {
        yield 'latency milliseconds' => [
            static fn () => new Usage(latencyMs: 1.5),
            '$latencyMs',
        ];
        yield 'time to first byte milliseconds' => [
            static fn () => new Usage(timeToFirstByteMs: 1.5),
            '$timeToFirstByteMs',
        ];
    }
}

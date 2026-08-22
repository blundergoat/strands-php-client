<?php

declare(strict_types=1);

/**
 * Exercises the usage values an application shows for cost and response speed.
 * It covers missing fields, fractional timings, numeric strings, and unsafe numbers.
 * Failures here mean a user could see misleading token or latency information.
 */

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
     * Covers "from array defaults every usage field to zero" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
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
     * Covers "from array rounds fractional token counts" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
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
     * Covers "from array rounds fractional timing values to integers" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
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
     * Covers "from array rejects unsafe numeric values" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
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
     * Supplies the input variants for the related usage-normalization scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: int|float|string}> Unsafe wire values and readable scenario labels.
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
     * Covers "from array preserves exact integer string at platform boundary" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
     *
     * @return void The assertion protects wrappers that encode large integer counters as strings.
     */
    public function testFromArrayPreservesExactIntegerStringAtPlatformBoundary(): void
    {
        $usage = Usage::fromArray(['input_tokens' => (string) PHP_INT_MAX]);

        $this->assertSame(PHP_INT_MAX, $usage->inputTokens);
    }

    /**
     * Covers "constructor rejects fractional timing values to keep integer contract" so token and timing readouts remain safe for users.
     * Use this regression case when usage parsing or numeric validation changes.
     *
     * @param \Closure(): Usage $constructUsage Builds the usage object for the selected timing field.
     * @param string $expectedParameter Identifies the rejected constructor parameter.
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
     * Supplies the input variants for the related usage-normalization scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: \Closure(): Usage, 1: string}> Constructor and parameter-name cases.
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

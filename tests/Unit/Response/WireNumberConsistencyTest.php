<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Citation\CitationLocation;
use StrandsPhpClient\Response\GuardrailAssessment;
use StrandsPhpClient\Streaming\StreamEvent;

/**
 * Exercises the optional numbers an app shows beside an answer: context hints, citation offsets, and guardrail confidence.
 *
 * Invoke responses, stream events, and citations each carry counts the UI may display or leave out, and every one of them hydrates the same way.
 * These tests protect that agreement, so a context hint or citation position is never shown on one screen after another screen hid it as unusable.
 * Use them when changing any fromArray() that reads a count, an offset, or a score a wrapper marked optional.
 */
class WireNumberConsistencyTest extends TestCase
{
    /**
     * Reads one wrapper number through every hydrator that can put it in front of a user.
     * Use it to compare the invoke, stream, and citation screens against a single wire value.
     *
     * @param int|float|string $wireValue Number exactly as a wrapper would send it.
     * @return array{invoke: ?int, stream: ?int, citation: ?int} What each screen would display; null means that screen hides the value.
     */
    private function wholeNumberAcrossHydrators(int|float|string $wireValue): array
    {
        $response = AgentResponse::fromArray(['text' => 'answer', 'context_size' => $wireValue]);
        $event = StreamEvent::fromArray(['type' => 'complete', 'text' => 'answer', 'context_size' => $wireValue]);
        $location = CitationLocation::fromArray(['start_page_index' => $wireValue]);

        return [
            'invoke' => $response->contextSize,
            'stream' => $event->contextSize,
            'citation' => $location->startPageIndex,
        ];
    }

    /**
     * Confirms the invoke, stream, and citation paths reach the same decision about one wrapper number.
     * A disagreement here means the same agent reply reads differently depending on which screen shows it.
     *
     * @param int|float|string $wireValue Number exactly as a wrapper would send it.
     * @param ?int $expectedDisplayValue Number every screen should show; null means every screen should hide it.
     * @return void
     */
    #[DataProvider('wholeNumberWireValueProvider')]
    public function testEveryHydratorAgreesOnOneWireNumber(int|float|string $wireValue, ?int $expectedDisplayValue): void
    {
        $displayedValues = $this->wholeNumberAcrossHydrators($wireValue);

        $this->assertSame(
            ['invoke' => $expectedDisplayValue, 'stream' => $expectedDisplayValue, 'citation' => $expectedDisplayValue],
            $displayedValues,
        );
    }

    /**
     * Supplies the wrapper numbers every screen has to read identically, including the ones no screen may show.
     * An empty provider would leave the shared parsing rule unverified for invoke, stream, and citation alike.
     *
     * @return iterable<string, array{0: int|float|string, 1: ?int}> Wire values paired with the value every screen should show.
     */
    public static function wholeNumberWireValueProvider(): iterable
    {
        yield 'plain integer' => [8192, 8192];
        yield 'integer sent as text' => ['8192', 8192];
        yield 'exact integer at the platform boundary' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'float sitting exactly on the lowest representable integer' => [(float) PHP_INT_MIN, PHP_INT_MIN];
        yield 'fractional value rounds to whole' => [3.7, 4];
        yield 'text beyond float range is hidden' => ['1e309', null];
        yield 'finite value beyond integer range is hidden' => [1.0e30, null];
        yield 'non-numeric text is hidden' => ['not-a-number', null];
    }

    /**
     * Confirms an unusable confidence is dropped, so an app can still JSON-encode the guardrail decision it shows the user.
     * Use this when changing guardrail hydration, because infinity would make json_encode() fail on the app's own response.
     *
     * @return void
     */
    public function testNonFiniteConfidenceIsOmittedSoTheAssessmentStaysEncodable(): void
    {
        $assessment = GuardrailAssessment::fromArray(['name' => 'pii', 'confidence' => '1e309']);

        $this->assertNull($assessment->confidence);
        $this->assertSame('{"confidence":null}', json_encode(['confidence' => $assessment->confidence]));
    }

    /**
     * Confirms a real score still reaches the app unchanged, proving the guard hid unusable values without hiding usable ones.
     * Use it as the counterweight to the non-finite case above.
     *
     * @return void
     */
    public function testUsableConfidenceScoresAreStillPreserved(): void
    {
        $stringScore = GuardrailAssessment::fromArray(['confidence' => '0.87']);
        $numericScore = GuardrailAssessment::fromArray(['confidence' => 1]);

        $this->assertSame(0.87, $stringScore->confidence);
        $this->assertSame(1.0, $numericScore->confidence);
    }
}

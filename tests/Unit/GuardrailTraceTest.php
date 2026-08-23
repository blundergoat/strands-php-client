<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;
use StrandsPhpClient\Response\GuardrailTrace;

/**
 * Verifies guardrail traces hydrate supported fields, discard malformed assessments, and expose typed assessment objects.
 *
 * Use these tests when changing safety-result parsing or the cached assessment view.
 * They protect the safety explanation a calling application can show after an intervention.
 */
class GuardrailTraceTest extends TestCase
{
    /**
     * Builds a complete guardrail trace that an app could show after an intervention.
     *
     * @return array<string, mixed> Wire fields supplied to GuardrailTrace::fromArray(); never empty.
     */
    private function completeGuardrailTracePayload(): array
    {
        return [
            'action' => 'INTERVENED',
            'assessments' => [
                ['type' => 'content_filter', 'policy' => 'harmful', 'action' => 'BLOCKED'],
                ['type' => 'topic_filter', 'policy' => 'off_topic', 'action' => 'BLOCKED'],
            ],
            'model_output' => 'The original unsafe response',
        ];
    }

    /**
     * Confirms fromArray() hydrates all fields so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $traceData = $this->completeGuardrailTracePayload();

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertSame('INTERVENED', $trace->action);
        $this->assertCount(2, $trace->assessments);
        $this->assertSame('content_filter', $trace->assessments[0]['type']);
        $this->assertSame('topic_filter', $trace->assessments[1]['type']);
        $this->assertSame('The original unsafe response', $trace->modelOutput);
    }

    /**
     * Confirms fromArray() handles missing fields so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $trace = GuardrailTrace::fromArray([]);

        $this->assertSame('', $trace->action);
        $this->assertSame([], $trace->assessments);
        $this->assertNull($trace->modelOutput);
    }
    /**
     * Builds a trace containing valid and malformed assessment entries.
     *
     * @return array<string, mixed> Mixed assessment values supplied to defensive parsing; never empty.
     */
    private function mixedGuardrailAssessmentPayload(): array
    {
        return [
            'action' => 'NONE',
            'assessments' => [
                ['type' => 'valid'],
                'not_an_array',
                42,
                ['type' => 'also_valid'],
            ],
        ];
    }


    /**
     * Confirms fromArray() filters non-array assessments so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testFromArrayFiltersNonArrayAssessments(): void
    {
        $traceData = $this->mixedGuardrailAssessmentPayload();

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertCount(2, $trace->assessments);
        $this->assertSame('valid', $trace->assessments[0]['type']);
        $this->assertSame('also_valid', $trace->assessments[1]['type']);
    }

    /**
     * Confirms fromArray() handles a non-array assessment list so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testFromArrayHandlesNonArrayAssessments(): void
    {
        $traceData = [
            'action' => 'NONE',
            'assessments' => 'not_an_array',
        ];

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertSame([], $trace->assessments);
    }

    /**
     * Confirms fromArray() ignores non-string model output so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testFromArrayHandlesNonStringModelOutput(): void
    {
        $traceData = [
            'action' => 'NONE',
            'model_output' => 123,
        ];

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertNull($trace->modelOutput);
    }

    /**
     * Confirms getAssessmentObjects() returns typed list so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testGetAssessmentObjectsReturnsTypedList(): void
    {
        $traceData = [
            'action' => 'INTERVENED',
            'assessments' => [
                ['type' => 'content_filter', 'action' => 'BLOCKED'],
                ['type' => 'topic_filter', 'action' => 'NONE'],
            ],
        ];

        $trace = GuardrailTrace::fromArray($traceData);
        $objects = $trace->getAssessmentObjects();

        $this->assertCount(2, $objects);
        $this->assertInstanceOf(GuardrailAssessment::class, $objects[0]);
        $this->assertSame('content_filter', $objects[0]->type);
        $this->assertSame('BLOCKED', $objects[0]->action);
        $this->assertInstanceOf(GuardrailAssessment::class, $objects[1]);
        $this->assertSame('topic_filter', $objects[1]->type);
    }

    /**
     * Confirms getAssessmentObjects() caches result so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testGetAssessmentObjectsCachesResult(): void
    {
        $traceData = [
            'action' => 'NONE',
            'assessments' => [
                ['type' => 'test'],
            ],
        ];

        $trace = GuardrailTrace::fromArray($traceData);
        $first = $trace->getAssessmentObjects();
        $second = $trace->getAssessmentObjects();

        $this->assertSame($first, $second);
    }

    /**
     * Confirms getAssessmentObjects() returns empty for no assessments so apps can safely present a guardrail result.
     *
     * @return void
     */
    public function testGetAssessmentObjectsReturnsEmptyForNoAssessments(): void
    {
        $trace = GuardrailTrace::fromArray(['action' => 'NONE']);

        $this->assertSame([], $trace->getAssessmentObjects());
    }
}

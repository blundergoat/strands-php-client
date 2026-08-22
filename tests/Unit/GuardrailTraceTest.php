<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Guardrail Trace behavior for app integrations.
 *
 * Use this file when changing Guardrail Trace or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;
use StrandsPhpClient\Response\GuardrailTrace;

/**
 * Exercises Guardrail Trace through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class GuardrailTraceTest extends TestCase
{
    /**
     * Data fixture for testFromArrayHydratesAllFields().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayHydratesAllFields(): array
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
     * Confirms fromArray() hydrates all fields so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $traceData = $this->dataForFromArrayHydratesAllFields();

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertSame('INTERVENED', $trace->action);
        $this->assertCount(2, $trace->assessments);
        $this->assertSame('content_filter', $trace->assessments[0]['type']);
        $this->assertSame('topic_filter', $trace->assessments[1]['type']);
        $this->assertSame('The original unsafe response', $trace->modelOutput);
    }

    /**
     * Confirms fromArray() handles missing fields so the app renders trustworthy answer details.
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
     * Data fixture for testFromArrayFiltersNonArrayAssessments().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayFiltersNonArrayAssessments(): array
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
     * Confirms fromArray() filters non array assessments so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayFiltersNonArrayAssessments(): void
    {
        $traceData = $this->dataForFromArrayFiltersNonArrayAssessments();

        $trace = GuardrailTrace::fromArray($traceData);

        $this->assertCount(2, $trace->assessments);
        $this->assertSame('valid', $trace->assessments[0]['type']);
        $this->assertSame('also_valid', $trace->assessments[1]['type']);
    }

    /**
     * Confirms fromArray() handles non array assessments so the app renders trustworthy answer details.
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
     * Confirms fromArray() handles non string model output so the app renders trustworthy answer details.
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
     * Confirms getAssessmentObjects() returns typed list so the app renders trustworthy answer details.
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
     * Confirms getAssessmentObjects() caches result so the app renders trustworthy answer details.
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
     * Confirms getAssessmentObjects() returns empty for no assessments so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testGetAssessmentObjectsReturnsEmptyForNoAssessments(): void
    {
        $trace = GuardrailTrace::fromArray(['action' => 'NONE']);

        $this->assertSame([], $trace->getAssessmentObjects());
    }
}

<?php

declare(strict_types=1);

/**
 * Tests caller-visible Guardrail Trace behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;
use StrandsPhpClient\Response\GuardrailTrace;

/**
 * Verifies Guardrail Trace behavior that application users rely on.
 */
class GuardrailTraceTest extends TestCase
{
    /**
     * Data fixture for testFromArrayHydratesAllFields().
     *
     * @return array<string, mixed> Scenarios that keep from array hydrates all fields behavior stable for app callers.
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
     * Verifies that from array hydrates all fields.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $data = $this->dataForFromArrayHydratesAllFields();

        $trace = GuardrailTrace::fromArray($data);

        $this->assertSame('INTERVENED', $trace->action);
        $this->assertCount(2, $trace->assessments);
        $this->assertSame('content_filter', $trace->assessments[0]['type']);
        $this->assertSame('topic_filter', $trace->assessments[1]['type']);
        $this->assertSame('The original unsafe response', $trace->modelOutput);
    }

    /**
     * Verifies that from array handles missing fields.
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
     * @return array<string, mixed> Scenarios that keep from array filters non array assessments behavior stable for app callers.
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
     * Verifies that from array filters non array assessments.
     *
     * @return void
     */
    public function testFromArrayFiltersNonArrayAssessments(): void
    {
        $data = $this->dataForFromArrayFiltersNonArrayAssessments();

        $trace = GuardrailTrace::fromArray($data);

        $this->assertCount(2, $trace->assessments);
        $this->assertSame('valid', $trace->assessments[0]['type']);
        $this->assertSame('also_valid', $trace->assessments[1]['type']);
    }

    /**
     * Verifies that from array handles non array assessments.
     *
     * @return void
     */
    public function testFromArrayHandlesNonArrayAssessments(): void
    {
        $data = [
            'action' => 'NONE',
            'assessments' => 'not_an_array',
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertSame([], $trace->assessments);
    }

    /**
     * Verifies that from array handles non string model output.
     *
     * @return void
     */
    public function testFromArrayHandlesNonStringModelOutput(): void
    {
        $data = [
            'action' => 'NONE',
            'model_output' => 123,
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertNull($trace->modelOutput);
    }

    /**
     * Verifies that get assessment objects returns typed list.
     *
     * @return void
     */
    public function testGetAssessmentObjectsReturnsTypedList(): void
    {
        $data = [
            'action' => 'INTERVENED',
            'assessments' => [
                ['type' => 'content_filter', 'action' => 'BLOCKED'],
                ['type' => 'topic_filter', 'action' => 'NONE'],
            ],
        ];

        $trace = GuardrailTrace::fromArray($data);
        $objects = $trace->getAssessmentObjects();

        $this->assertCount(2, $objects);
        $this->assertInstanceOf(GuardrailAssessment::class, $objects[0]);
        $this->assertSame('content_filter', $objects[0]->type);
        $this->assertSame('BLOCKED', $objects[0]->action);
        $this->assertInstanceOf(GuardrailAssessment::class, $objects[1]);
        $this->assertSame('topic_filter', $objects[1]->type);
    }

    /**
     * Verifies that get assessment objects caches result.
     *
     * @return void
     */
    public function testGetAssessmentObjectsCachesResult(): void
    {
        $data = [
            'action' => 'NONE',
            'assessments' => [
                ['type' => 'test'],
            ],
        ];

        $trace = GuardrailTrace::fromArray($data);
        $first = $trace->getAssessmentObjects();
        $second = $trace->getAssessmentObjects();

        $this->assertSame($first, $second);
    }

    /**
     * Verifies that get assessment objects returns empty for no assessments.
     *
     * @return void
     */
    public function testGetAssessmentObjectsReturnsEmptyForNoAssessments(): void
    {
        $trace = GuardrailTrace::fromArray(['action' => 'NONE']);

        $this->assertSame([], $trace->getAssessmentObjects());
    }
}

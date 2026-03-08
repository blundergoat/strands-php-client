<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailTrace;

class GuardrailTraceTest extends TestCase
{
    public function testFromArrayHydratesAllFields(): void
    {
        $data = [
            'action' => 'INTERVENED',
            'assessments' => [
                ['type' => 'content_filter', 'policy' => 'harmful', 'action' => 'BLOCKED'],
                ['type' => 'topic_filter', 'policy' => 'off_topic', 'action' => 'BLOCKED'],
            ],
            'model_output' => 'The original unsafe response',
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertSame('INTERVENED', $trace->action);
        $this->assertCount(2, $trace->assessments);
        $this->assertSame('content_filter', $trace->assessments[0]['type']);
        $this->assertSame('topic_filter', $trace->assessments[1]['type']);
        $this->assertSame('The original unsafe response', $trace->modelOutput);
    }

    public function testFromArrayHandlesMissingFields(): void
    {
        $trace = GuardrailTrace::fromArray([]);

        $this->assertSame('', $trace->action);
        $this->assertSame([], $trace->assessments);
        $this->assertNull($trace->modelOutput);
    }

    public function testFromArrayFiltersNonArrayAssessments(): void
    {
        $data = [
            'action' => 'NONE',
            'assessments' => [
                ['type' => 'valid'],
                'not_an_array',
                42,
                ['type' => 'also_valid'],
            ],
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertCount(2, $trace->assessments);
        $this->assertSame('valid', $trace->assessments[0]['type']);
        $this->assertSame('also_valid', $trace->assessments[1]['type']);
    }

    public function testFromArrayHandlesNonArrayAssessments(): void
    {
        $data = [
            'action' => 'NONE',
            'assessments' => 'not_an_array',
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertSame([], $trace->assessments);
    }

    public function testFromArrayHandlesNonStringModelOutput(): void
    {
        $data = [
            'action' => 'NONE',
            'model_output' => 123,
        ];

        $trace = GuardrailTrace::fromArray($data);

        $this->assertNull($trace->modelOutput);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;

class GuardrailAssessmentTest extends TestCase
{
    public function testFromArrayWithAllPolicies(): void
    {
        $data = [
            'type' => 'content_filter',
            'action' => 'BLOCKED',
            'topic_policy' => ['name' => 'violence', 'action' => 'BLOCKED'],
            'content_policy' => ['name' => 'harmful', 'confidence' => 'HIGH'],
            'word_policy' => ['managed_word_lists' => ['profanity']],
            'sensitive_information_policy' => ['pii_entities' => ['SSN']],
            'contextual_grounding_policy' => ['threshold' => 0.7],
        ];

        $assessment = GuardrailAssessment::fromArray($data);

        $this->assertSame('content_filter', $assessment->type);
        $this->assertSame('BLOCKED', $assessment->action);
        $this->assertSame(['name' => 'violence', 'action' => 'BLOCKED'], $assessment->topicPolicy);
        $this->assertSame(['name' => 'harmful', 'confidence' => 'HIGH'], $assessment->contentPolicy);
        $this->assertSame(['managed_word_lists' => ['profanity']], $assessment->wordPolicy);
        $this->assertSame(['pii_entities' => ['SSN']], $assessment->sensitiveInformationPolicy);
        $this->assertSame(['threshold' => 0.7], $assessment->contextualGroundingPolicy);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = [
            'type' => 'topic_filter',
            'action' => 'NONE',
        ];

        $assessment = GuardrailAssessment::fromArray($data);

        $this->assertSame('topic_filter', $assessment->type);
        $this->assertSame('NONE', $assessment->action);
        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
        $this->assertNull($assessment->wordPolicy);
        $this->assertNull($assessment->sensitiveInformationPolicy);
        $this->assertNull($assessment->contextualGroundingPolicy);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $assessment = GuardrailAssessment::fromArray([]);

        $this->assertNull($assessment->type);
        $this->assertNull($assessment->action);
    }

    public function testFromArrayIgnoresNonArrayPolicies(): void
    {
        $data = [
            'type' => 'test',
            'topic_policy' => 'not_an_array',
            'content_policy' => 42,
        ];

        $assessment = GuardrailAssessment::fromArray($data);

        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
    }
}

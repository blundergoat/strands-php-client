<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;

/**
 * Verifies guardrail assessments hydrate supported policy maps and accept only safe confidence values.
 *
 * Use these tests when changing assessment parsing or optional policy categories.
 * They protect the safety reason and score a calling application can present after an intervention.
 */
class GuardrailAssessmentTest extends TestCase
{
    /**
     * Builds an intervention containing every supported guardrail policy category.
     *
     * @return array<string, mixed> Complete assessment fields supplied by the agent; never empty.
     */
    private function completeAssessmentPayload(): array
    {
        return [
            'type' => 'content_filter',
            'action' => 'BLOCKED',
            'topic_policy' => ['name' => 'violence', 'action' => 'BLOCKED'],
            'content_policy' => ['name' => 'harmful', 'confidence' => 'HIGH'],
            'word_policy' => ['managed_word_lists' => ['profanity']],
            'sensitive_information_policy' => ['pii_entities' => ['SSN']],
            'contextual_grounding_policy' => ['threshold' => 0.7],
        ];
    }

    /**
     * Confirms fromArray() hydrates all policy assessments so the app can explain a guardrail decision.
     *
     * @return void
     */
    public function testFromArrayWithAllPolicies(): void
    {
        $assessmentData = $this->completeAssessmentPayload();

        $assessment = GuardrailAssessment::fromArray($assessmentData);

        $this->assertSame('content_filter', $assessment->type);
        $this->assertSame('BLOCKED', $assessment->action);
        $this->assertSame(['name' => 'violence', 'action' => 'BLOCKED'], $assessment->topicPolicy);
        $this->assertSame(['name' => 'harmful', 'confidence' => 'HIGH'], $assessment->contentPolicy);
        $this->assertSame(['managed_word_lists' => ['profanity']], $assessment->wordPolicy);
        $this->assertSame(['pii_entities' => ['SSN']], $assessment->sensitiveInformationPolicy);
        $this->assertSame(['threshold' => 0.7], $assessment->contextualGroundingPolicy);
    }
    /**
     * Builds the smallest guardrail result an app can still identify and display.
     *
     * @return array<string, mixed> Required assessment fields supplied by the agent; never empty.
     */
    private function minimalAssessmentPayload(): array
    {
        return [
            'type' => 'topic_filter',
            'action' => 'NONE',
        ];
    }


    /**
     * Confirms fromArray() accepts minimal assessment data so the app can show the available guardrail result.
     *
     * @return void
     */
    public function testFromArrayWithMinimalData(): void
    {
        $assessmentData = $this->minimalAssessmentPayload();

        $assessment = GuardrailAssessment::fromArray($assessmentData);

        $this->assertSame('topic_filter', $assessment->type);
        $this->assertSame('NONE', $assessment->action);
        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
        $this->assertNull($assessment->wordPolicy);
        $this->assertNull($assessment->sensitiveInformationPolicy);
        $this->assertNull($assessment->contextualGroundingPolicy);
    }

    /**
     * Confirms fromArray() gives empty assessment data safe defaults so the app can omit unavailable policy details.
     *
     * @return void
     */
    public function testFromArrayWithEmptyData(): void
    {
        $assessment = GuardrailAssessment::fromArray([]);

        $this->assertNull($assessment->type);
        $this->assertNull($assessment->action);
    }
    /**
     * Builds malformed policy fields like those a loosely typed endpoint could return.
     *
     * @return array<string, mixed> Invalid policy values supplied to defensive parsing; never empty.
     */
    private function malformedPolicyPayload(): array
    {
        return [
            'type' => 'test',
            'topic_policy' => 'not_an_array',
            'content_policy' => 42,
        ];
    }


    /**
     * Confirms fromArray() ignores non-array policies so apps do not present malformed safety details.
     *
     * @return void
     */
    public function testFromArrayIgnoresNonArrayPolicies(): void
    {
        $assessmentData = $this->malformedPolicyPayload();

        $assessment = GuardrailAssessment::fromArray($assessmentData);

        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
    }

    /**
     * Verifies confidence parsing across valid and malformed wire values.
     *
     * @return void
     */
    public function testFromArrayParsesOnlyNumericConfidenceValues(): void
    {
        $cases = [
            'integer' => [1, 1.0],
            'float' => [0.75, 0.75],
            'numeric string' => ['0.625', 0.625],
            'non-numeric string' => ['high', null],
            'boolean' => [true, null],
            'array' => [[0.5], null],
        ];

        // Each wire representation must produce the confidence value, or null, that the safety UI can trust.
        foreach ($cases as $caseName => [$confidenceValue, $expectedConfidence]) {
            $assessment = GuardrailAssessment::fromArray(['confidence' => $confidenceValue]);

            $this->assertSame($expectedConfidence, $assessment->confidence, "Failed for {$caseName}");
        }
    }
}

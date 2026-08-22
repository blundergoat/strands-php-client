<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Guardrail Assessment behavior for app integrations.
 *
 * Use this file when changing Guardrail Assessment or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;

/**
 * Exercises Guardrail Assessment through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class GuardrailAssessmentTest extends TestCase
{
    /**
     * Data fixture for testFromArrayWithAllPolicies().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayWithAllPolicies(): array
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
        $assessmentData = $this->dataForFromArrayWithAllPolicies();

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
     * Data fixture for testFromArrayWithMinimalData().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayWithMinimalData(): array
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
        $assessmentData = $this->dataForFromArrayWithMinimalData();

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
     * Data fixture for testFromArrayIgnoresNonArrayPolicies().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayIgnoresNonArrayPolicies(): array
    {
        return [
            'type' => 'test',
            'topic_policy' => 'not_an_array',
            'content_policy' => 42,
        ];
    }


    /**
     * Confirms fromArray() ignores non array policies so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayIgnoresNonArrayPolicies(): void
    {
        $assessmentData = $this->dataForFromArrayIgnoresNonArrayPolicies();

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

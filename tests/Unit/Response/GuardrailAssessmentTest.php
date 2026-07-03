<?php

declare(strict_types=1);

/**
 * Tests caller-visible Guardrail Assessment behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\GuardrailAssessment;

/**
 * Verifies Guardrail Assessment behavior that application users rely on.
 */
class GuardrailAssessmentTest extends TestCase
{
    /**
     * Data fixture for testFromArrayWithAllPolicies().
     *
     * @return array<string, mixed> Scenarios that keep from array with all policies behavior stable for app callers.
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
     * Verifies that from array with all policies.
     *
     * @return void
     */
    public function testFromArrayWithAllPolicies(): void
    {
        $data = $this->dataForFromArrayWithAllPolicies();

        $assessment = GuardrailAssessment::fromArray($data);

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
     * @return array<string, mixed> Scenarios that keep from array with minimal data behavior stable for app callers.
     */
    private function dataForFromArrayWithMinimalData(): array
    {
        return [
            'type' => 'topic_filter',
            'action' => 'NONE',
        ];
    }


    /**
     * Verifies that from array with minimal data.
     *
     * @return void
     */
    public function testFromArrayWithMinimalData(): void
    {
        $data = $this->dataForFromArrayWithMinimalData();

        $assessment = GuardrailAssessment::fromArray($data);

        $this->assertSame('topic_filter', $assessment->type);
        $this->assertSame('NONE', $assessment->action);
        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
        $this->assertNull($assessment->wordPolicy);
        $this->assertNull($assessment->sensitiveInformationPolicy);
        $this->assertNull($assessment->contextualGroundingPolicy);
    }

    /**
     * Verifies that from array with empty data.
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
     * @return array<string, mixed> Scenarios that keep from array ignores non array policies behavior stable for app callers.
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
     * Verifies that from array ignores non array policies.
     *
     * @return void
     */
    public function testFromArrayIgnoresNonArrayPolicies(): void
    {
        $data = $this->dataForFromArrayIgnoresNonArrayPolicies();

        $assessment = GuardrailAssessment::fromArray($data);

        $this->assertNull($assessment->topicPolicy);
        $this->assertNull($assessment->contentPolicy);
    }
}

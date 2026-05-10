<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * A typed guardrail assessment from a guardrail trace.
 */
final readonly class GuardrailAssessment
{
    /**
     * @param string|null               $type                         Assessment type.
     * @param string|null               $action                       Action taken (e.g. 'BLOCKED').
     * @param array<string, mixed>|null $topicPolicy                  Topic policy details.
     * @param array<string, mixed>|null $contentPolicy                Content policy details.
     * @param array<string, mixed>|null $wordPolicy                   Word policy details.
     * @param array<string, mixed>|null $sensitiveInformationPolicy   Sensitive information policy details.
     * @param array<string, mixed>|null $contextualGroundingPolicy    Contextual grounding policy details.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $action = null,
        public ?array $topicPolicy = null,
        public ?array $contentPolicy = null,
        public ?array $wordPolicy = null,
        public ?array $sensitiveInformationPolicy = null,
        public ?array $contextualGroundingPolicy = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $topicPolicy */
        $topicPolicy = is_array($data['topic_policy'] ?? null) ? $data['topic_policy'] : null;
        /** @var array<string, mixed>|null $contentPolicy */
        $contentPolicy = is_array($data['content_policy'] ?? null) ? $data['content_policy'] : null;
        /** @var array<string, mixed>|null $wordPolicy */
        $wordPolicy = is_array($data['word_policy'] ?? null) ? $data['word_policy'] : null;
        /** @var array<string, mixed>|null $sensitiveInformationPolicy */
        $sensitiveInformationPolicy = is_array($data['sensitive_information_policy'] ?? null) ? $data['sensitive_information_policy'] : null;
        /** @var array<string, mixed>|null $contextualGroundingPolicy */
        $contextualGroundingPolicy = is_array($data['contextual_grounding_policy'] ?? null) ? $data['contextual_grounding_policy'] : null;

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            action: is_string($data['action'] ?? null) ? $data['action'] : null,
            topicPolicy: $topicPolicy,
            contentPolicy: $contentPolicy,
            wordPolicy: $wordPolicy,
            sensitiveInformationPolicy: $sensitiveInformationPolicy,
            contextualGroundingPolicy: $contextualGroundingPolicy,
        );
    }
}

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
     * @param string|null               $name                         Normalized assessment name (e.g. 'safety').
     * @param string|null               $result                       Normalized result (e.g. 'blocked', 'allowed').
     * @param float|null                $confidence                   Confidence score from 0.0 to 1.0.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $action = null,
        public ?array $topicPolicy = null,
        public ?array $contentPolicy = null,
        public ?array $wordPolicy = null,
        public ?array $sensitiveInformationPolicy = null,
        public ?array $contextualGroundingPolicy = null,
        public ?string $name = null,
        public ?string $result = null,
        public ?float $confidence = null,
    ) {
    }

    /**
     * Hydrates caller-facing data from the agent response.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $topicPolicy validated before app code uses it. */
        $topicPolicy = is_array($data['topic_policy'] ?? null) ? $data['topic_policy'] : null;
        /** @var array<string, mixed>|null $contentPolicy validated before app code uses it. */
        $contentPolicy = is_array($data['content_policy'] ?? null) ? $data['content_policy'] : null;
        /** @var array<string, mixed>|null $wordPolicy validated before app code uses it. */
        $wordPolicy = is_array($data['word_policy'] ?? null) ? $data['word_policy'] : null;
        /** @var array<string, mixed>|null $sensitiveInformationPolicy validated before app code uses it. */
        $sensitiveInformationPolicy = is_array($data['sensitive_information_policy'] ?? null) ? $data['sensitive_information_policy'] : null;
        /** @var array<string, mixed>|null $contextualGroundingPolicy validated before app code uses it. */
        $contextualGroundingPolicy = is_array($data['contextual_grounding_policy'] ?? null) ? $data['contextual_grounding_policy'] : null;

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            action: is_string($data['action'] ?? null) ? $data['action'] : null,
            topicPolicy: $topicPolicy,
            contentPolicy: $contentPolicy,
            wordPolicy: $wordPolicy,
            sensitiveInformationPolicy: $sensitiveInformationPolicy,
            contextualGroundingPolicy: $contextualGroundingPolicy,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            result: is_string($data['result'] ?? null) ? $data['result'] : null,
            confidence: self::float($data, 'confidence'),
        );
    }

    /**
     * Supports the float step in the app-facing flow.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @param string $key Payload field name being read or written.
     * @return ?float Value returned to app code.
     */
    private static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * One policy check the guardrail ran on a turn, in typed form.
 *
 * It records the applicable policy, action, and confidence returned for the evaluated turn.
 * Callers can inspect why a response was blocked or altered; fields remain null when that policy did not report them.
 */
final readonly class GuardrailAssessment
{
    /**
     * Hold one policy check the guardrail ran on a turn.
     *
     * Usually built by fromArray() from a guardrail trace.
     *
     * @param string|null $type Assessment type; null means the wrapper did not identify it.
     * @param string|null $action Action such as BLOCKED; null means no action was reported.
     * @param array<string, mixed>|null $topicPolicy Details; null means this policy did not apply, while an empty map is preserved.
     * @param array<string, mixed>|null $contentPolicy Details; null means this policy did not apply, while an empty map is preserved.
     * @param array<string, mixed>|null $wordPolicy Details; null means this policy did not apply, while an empty map is preserved.
     * @param array<string, mixed>|null $sensitiveInformationPolicy Details; null means this policy did not apply; an empty map is preserved.
     * @param array<string, mixed>|null $contextualGroundingPolicy Details; null means this policy did not apply; an empty map is preserved.
     * @param string|null $name Normalized policy name; null means unavailable, while an empty string is preserved.
     * @param string|null $result Normalized result; null means unavailable, while an empty string is preserved.
     * @param float|null $confidence Confidence from 0.0 to 1.0; null means no usable score was reported.
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
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data Raw assessment map; an empty map creates all-null fields.
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
            confidence: WireNumber::optionalDecimal($data, 'confidence'),
        );
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * One policy check the guardrail ran on a turn, in typed form.
 *
 * Each assessment records which policy fired (topic, content, word,
 * sensitive-info, or grounding), what it did, and how confident it was. Apps
 * read these to explain to the user why a response was blocked or altered.
 * Every field is nullable because an assessment fills in only the parts that apply.
 */
final readonly class GuardrailAssessment
{
    /**
     * Hold one policy check the guardrail ran on a turn.
     *
     * Usually built by fromArray() from a guardrail trace.
     *
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
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
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
     * Read a confidence score, tolerating number-or-string wire values.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $key Assessment field holding the score (e.g. 'confidence').
     * @return ?float Score the app can show as a percentage, or null when absent.
     */
    private static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        // Accept a plain number as-is (an int or float both become a float score).
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        // Some wrappers send the score as a numeric string (e.g. "0.87").
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}

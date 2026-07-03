<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Trace data from a guardrail intervention.
 *
 * Contains the action taken, individual assessments, and the original
 * model output before the guardrail intervened.
 */
final class GuardrailTrace
{
    /** @var list<GuardrailAssessment>|null */
    private ?array $assessmentObjects = null;

    /**
     * @param string $action                        The guardrail action (e.g. 'INTERVENED', 'NONE').
     * @param list<array<string, mixed>> $assessments  Individual guardrail assessments.
     * @param string|null $modelOutput               The model's original output before intervention.
     */
    public function __construct(
        public readonly string $action,
        public readonly array $assessments = [],
        public readonly ?string $modelOutput = null,
    ) {
    }

    /**
     * Get assessments as typed DTOs, hydrated from the raw $assessments arrays.
     *
     * @return list<GuardrailAssessment> Value returned to app code.
     */
    public function getAssessmentObjects(): array
    {
        if ($this->assessmentObjects !== null) {
            return $this->assessmentObjects;
        }

        $this->assessmentObjects = [];
        foreach ($this->assessments as $data) {
            $this->assessmentObjects[] = GuardrailAssessment::fromArray($data);
        }

        return $this->assessmentObjects;
    }

    /**
     * Hydrates caller-facing data from the agent response.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        $rawAssessments = is_array($data['assessments'] ?? null) ? $data['assessments'] : [];
        /** @var list<array<string, mixed>> $assessments validated before app code uses it. */
        $assessments = [];
        foreach ($rawAssessments as $assessment) {
            if (is_array($assessment)) {
                /** @var array<string, mixed> $assessment validated before app code uses it. */
                $assessments[] = $assessment;
            }
        }

        return new self(
            action: is_string($data['action'] ?? null) ? $data['action'] : '',
            assessments: $assessments,
            modelOutput: is_string($data['model_output'] ?? null) ? $data['model_output'] : null,
        );
    }
}

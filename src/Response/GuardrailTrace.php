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
     * @return list<GuardrailAssessment>
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawAssessments = is_array($data['assessments'] ?? null) ? $data['assessments'] : [];
        /** @var list<array<string, mixed>> $assessments */
        $assessments = [];
        foreach ($rawAssessments as $assessment) {
            if (is_array($assessment)) {
                /** @var array<string, mixed> $assessment */
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

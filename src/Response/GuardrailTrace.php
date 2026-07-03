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
     * Hold a guardrail intervention's action and its assessments.
     *
     * Usually built by fromArray() from the agent response.
     *
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
     * @return list<GuardrailAssessment> Typed assessments to show the user; empty when the guardrail flagged nothing.
     */
    public function getAssessmentObjects(): array
    {
        // Hydrate once, then reuse — the app may render this list on every redraw.
        if ($this->assessmentObjects !== null) {
            return $this->assessmentObjects;
        }

        $this->assessmentObjects = [];
        // Turn each raw assessment into a typed object the UI can show (what was flagged, why).
        foreach ($this->assessments as $data) {
            $this->assessmentObjects[] = GuardrailAssessment::fromArray($data);
        }

        return $this->assessmentObjects;
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        $rawAssessments = is_array($data['assessments'] ?? null) ? $data['assessments'] : [];
        /** @var list<array<string, mixed>> $assessments validated before app code uses it. */
        $assessments = [];
        // Collect each policy assessment the guardrail reported for this turn.
        foreach ($rawAssessments as $assessment) {
            // Skip anything that isn't a well-formed assessment object.
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

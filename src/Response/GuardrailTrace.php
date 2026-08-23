<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Describes a guardrail decision attached to an agent response.
 *
 * Read the action for the overall outcome, assessments for policy details, and modelOutput only when the caller is authorized to use pre-intervention text.
 * Apps normally receive this through AgentResponse or StreamResult; absence there means no intervention detail was returned.
 */
final readonly class GuardrailTrace
{
    /** @var list<GuardrailAssessment> */
    private array $assessmentObjects;

    /**
     * Stores the action, policy assessments, and optional pre-intervention model text.
     * Use fromArray() for agent JSON; direct construction is mainly for tests and app-owned fixtures.
     *
     * @param string $action Guardrail action such as INTERVENED or NONE; empty means the wrapper omitted a usable action.
     * @param list<array<string, mixed>> $assessments Raw policy assessments; empty means no assessment detail is available to show.
     * @param string|null $modelOutput Original model text; null means unavailable, while an empty string is a returned but blank output.
     */
    public function __construct(
        public string $action,
        public array $assessments = [],
        public ?string $modelOutput = null,
    ) {
        $assessmentObjects = [];
        // Build typed assessments once so repeated reads do not rehydrate the raw maps.
        foreach ($assessments as $assessmentData) {
            $assessmentObjects[] = GuardrailAssessment::fromArray($assessmentData);
        }

        $this->assessmentObjects = $assessmentObjects;
    }

    /**
     * Returns typed policy assessments for caller-side handling.
     * An empty list means there is no policy-level detail.
     *
     * @return list<GuardrailAssessment> Typed assessments; empty when the guardrail reported no assessment objects.
     */
    public function getAssessmentObjects(): array
    {
        return $this->assessmentObjects;
    }

    /**
     * Defensively converts raw guardrail JSON into fields safe for an intervention notice.
     * Use it at the response boundary; missing or malformed optional values become empty collections or null.
     *
     * @param array<string, mixed> $data Raw decoded guardrail JSON; an empty map creates an empty action with no detail.
     * @return self Hydrated guardrail trace; never null.
     */
    public static function fromArray(array $data): self
    {
        $rawAssessments = is_array($data['assessments'] ?? null) ? $data['assessments'] : [];
        /** @var list<array<string, mixed>> $assessments validated before app code uses it. */
        $assessments = [];
        // Collect each policy assessment the guardrail reported for this turn.
        foreach ($rawAssessments as $assessmentData) {
            // Skip anything that isn't a well-formed assessment object.
            if (is_array($assessmentData)) {
                /** @var array<string, mixed> $assessmentData validated before app code uses it. */
                $assessments[] = $assessmentData;
            }
        }

        return new self(
            action: is_string($data['action'] ?? null) ? $data['action'] : '',
            assessments: $assessments,
            modelOutput: is_string($data['model_output'] ?? null) ? $data['model_output'] : null,
        );
    }
}

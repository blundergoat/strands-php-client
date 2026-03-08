<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Trace data from a guardrail intervention.
 *
 * Contains the action taken, individual assessments, and the original
 * model output before the guardrail intervened.
 */
final readonly class GuardrailTrace
{
    /**
     * @param string $action                        The guardrail action (e.g. 'INTERVENED', 'NONE').
     * @param list<array<string, mixed>> $assessments  Individual guardrail assessments.
     * @param string|null $modelOutput               The model's original output before intervention.
     */
    public function __construct(
        public string $action,
        public array $assessments = [],
        public ?string $modelOutput = null,
    ) {
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

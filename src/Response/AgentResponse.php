<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Represents the complete response from a synchronous invoke() call.
 *
 * Covers all response fields: text output, session continuity, token usage,
 * tool use history, structured output, interrupt control flow, guardrail
 * interventions, and citations. Unrecognised top-level fields are captured
 * in $metadata for forward-compatibility.
 */
class AgentResponse
{
    /**
     * @param string  $text               The agent's text response.
     * @param string|null  $agent          Agent name that handled the request.
     * @param string|null  $sessionId      Session ID for multi-turn conversations.
     * @param Usage   $usage              Token usage statistics.
     * @param list<array{name: string, duration_ms?: int}>  $toolsUsed  Tools the agent called.
     * @param bool    $hasObjective       Whether this agent had a secret objective active.
     * @param StopReason|null $stopReason  Why the agent stopped generating output.
     * @param array<string, mixed>|null $structuredOutput  Schema-validated structured output.
     * @param array<string, mixed> $metadata  Unrecognised top-level response fields (forward-compat).
     * @param list<InterruptDetail> $interrupts  Interrupts raised by the agent (human-in-the-loop).
     * @param GuardrailTrace|null $guardrailTrace  Guardrail intervention trace data.
     * @param list<array<string, mixed>> $citations  Citation content blocks from the response.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $agent = null,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
        public readonly bool $hasObjective = false,
        public readonly ?StopReason $stopReason = null,
        public readonly ?array $structuredOutput = null,
        public readonly array $metadata = [],
        public readonly array $interrupts = [],
        public readonly ?GuardrailTrace $guardrailTrace = null,
        public readonly array $citations = [],
    ) {
    }

    /**
     * Whether the agent was interrupted and is waiting for user input.
     */
    public function isInterrupted(): bool
    {
        return $this->interrupts !== [];
    }

    /**
     * Create from the raw JSON array returned by the /invoke endpoint.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $usage = self::parseUsage($data);

        $rawStopReason = $data['stop_reason'] ?? null;
        $stopReason = is_string($rawStopReason) ? StopReason::tryFrom($rawStopReason) : null;

        $rawStructuredOutput = $data['structured_output'] ?? null;
        /** @var array<string, mixed>|null $structuredOutput */
        $structuredOutput = is_array($rawStructuredOutput) ? $rawStructuredOutput : null;

        $knownKeys = [
            'text', 'agent', 'session_id', 'usage', 'tools_used',
            'has_objective', 'stop_reason', 'structured_output',
            'interrupts', 'guardrail_trace', 'trace', 'message',
        ];
        /** @var array<string, mixed> $metadata */
        $metadata = array_diff_key($data, array_flip($knownKeys));

        return new self(
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            agent: is_string($data['agent'] ?? null) ? $data['agent'] : null,
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            hasObjective: ($data['has_objective'] ?? false) === true,
            usage: $usage,
            toolsUsed: self::parseToolsUsed($data),
            stopReason: $stopReason,
            structuredOutput: $structuredOutput,
            metadata: $metadata,
            interrupts: self::parseInterrupts($data),
            guardrailTrace: self::parseGuardrailTrace($data),
            citations: self::parseCitations($data),
        );
    }

    /**
     * Parse usage statistics from the raw API data.
     *
     * @param array<string, mixed> $data
     */
    private static function parseUsage(array $data): Usage
    {
        /** @var array<string, mixed> $usageData */
        $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return Usage::fromArray($usageData);
    }

    /**
     * Extract and validate the tools_used array from raw API data.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{name: string, duration_ms?: int}>
     */
    private static function parseToolsUsed(array $data): array
    {
        $toolsUsed = [];
        $rawTools = is_array($data['tools_used'] ?? null) ? $data['tools_used'] : [];

        foreach ($rawTools as $tool) {
            if (!is_array($tool) || !isset($tool['name']) || !is_string($tool['name'])) {
                continue;
            }

            $entry = ['name' => $tool['name']];

            if (isset($tool['duration_ms']) && is_int($tool['duration_ms'])) {
                $entry['duration_ms'] = $tool['duration_ms'];
            }

            /** @var array{name: string, duration_ms?: int} $entry */
            $toolsUsed[] = $entry;
        }

        return $toolsUsed;
    }

    /**
     * Parse interrupt details from the raw API data.
     *
     * @param array<string, mixed> $data
     *
     * @return list<InterruptDetail>
     */
    private static function parseInterrupts(array $data): array
    {
        $rawInterrupts = $data['interrupts'] ?? null;
        if (!is_array($rawInterrupts)) {
            return [];
        }

        $interrupts = [];
        foreach ($rawInterrupts as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $interrupts[] = InterruptDetail::fromArray($item);
            }
        }

        return $interrupts;
    }

    /**
     * Parse guardrail trace from the raw API data.
     *
     * Supports both `guardrail_trace` (top-level) and `trace.guardrail` (nested).
     *
     * @param array<string, mixed> $data
     */
    private static function parseGuardrailTrace(array $data): ?GuardrailTrace
    {
        // Try top-level first
        $raw = $data['guardrail_trace'] ?? null;

        // Fall back to nested trace.guardrail
        if (!is_array($raw)) {
            $trace = $data['trace'] ?? null;
            if (is_array($trace)) {
                $raw = $trace['guardrail'] ?? null;
            }
        }

        if (!is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return GuardrailTrace::fromArray($raw);
    }

    /**
     * Extract citation content blocks from message.content[].
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function parseCitations(array $data): array
    {
        $message = $data['message'] ?? null;
        if (!is_array($message)) {
            return [];
        }

        $content = $message['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        $citations = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'citationsContent' || $type === 'citation') {
                /** @var array<string, mixed> $block */
                $citations[] = $block;
            }
        }

        return $citations;
    }
}

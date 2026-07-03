<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

use StrandsPhpClient\Exceptions\StrandsException;

/**
 * Represents the complete response from a synchronous invoke() call.
 *
 * Covers all response fields: text output, session continuity, token usage,
 * tool use history, structured output, interrupt control flow, guardrail
 * interventions, and citations. Unrecognised top-level fields are captured
 * in $metadata for backward-compatible forward-compatibility.
 */
class AgentResponse
{
    /** @var list<Citation\Citation>|null */
    private ?array $citationObjects = null;

    /**
     * @param string  $text               The agent's text response.
     * @param string|null  $agent          Agent name that handled the request.
     * @param string|null  $sessionId      Session ID for multi-turn conversations.
     * @param Usage   $usage              Token usage statistics.
     * @param list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}>  $toolsUsed  Tools the agent called.
     * @param bool    $hasObjective       Whether this agent had a secret objective active.
     * @param StopReason|null $stopReason  Why the agent stopped generating output.
     * @param array<string, mixed>|null $structuredOutput  Schema-validated structured output.
     * @param array<string, mixed> $metadata  Unrecognised top-level response fields (forward-compat).
     * @param list<InterruptDetail> $interrupts  Interrupts raised by the agent (human-in-the-loop).
     * @param GuardrailTrace|null $guardrailTrace  Guardrail intervention trace data.
     * @param list<array<string, mixed>> $citations  Citation content blocks from the response.
     * @param Message|null $message  Wrapper-normalized raw message envelope.
     * @param array<string, mixed> $wrapperMetadata  Top-level wrapper-owned metadata field.
     * @param int|null $contextSize  Current context size in tokens.
     * @param int|null $projectedContextSize  Projected next-turn context size in tokens.
     * @param string|null $rawStopReason  Raw stop reason, including unknown future values.
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
        public readonly ?Message $message = null,
        public readonly array $wrapperMetadata = [],
        public readonly ?int $contextSize = null,
        public readonly ?int $projectedContextSize = null,
        public readonly ?string $rawStopReason = null,
    ) {
    }

    /**
     * Whether the agent was interrupted and is waiting for user input.
     *
     * @return bool true when the caller-facing condition is met.
     */
    public function isInterrupted(): bool
    {
        return $this->interrupts !== [];
    }

    /**
     * Get citations as typed DTOs, hydrated from the raw $citations arrays.
     *
     * @return list<Citation\Citation> Value returned to app code.
     */
    public function getCitationObjects(): array
    {
        if ($this->citationObjects !== null) {
            return $this->citationObjects;
        }

        $this->citationObjects = [];
        foreach ($this->citations as $data) {
            $this->citationObjects[] = Citation\Citation::fromArray($data);
        }

        return $this->citationObjects;
    }

    /**
     * Hydrate structured output into a typed DTO.
     *
     * @template T of object
     *
     * @param class-string<T> $class DTO class used to hydrate structured output.
     *
     * @return T Value returned to app code.
     *
     * @throws StrandsException If no structured output is available or hydration fails.
     */
    public function structuredOutputAs(string $class): object
    {
        if ($this->structuredOutput === null) {
            throw new StrandsException('No structured output in response');
        }

        try {
            $reflectionClass = new \ReflectionClass($class);

            if ($reflectionClass->hasMethod('fromArray')) {
                $method = $reflectionClass->getMethod('fromArray');
                if ($method->isStatic() && $method->isPublic()) {
                    /** @var T */
                    return $method->invoke(null, $this->structuredOutput);
                }
            }

            /** @var T */
            return new $class(...$this->structuredOutput);
        } catch (StrandsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StrandsException(
                sprintf('Failed to hydrate structured output into %s: %s', $class, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * Create from the raw JSON array returned by the /invoke endpoint.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        $usage = self::parseUsage($data);

        $rawStopReason = $data['stop_reason'] ?? null;
        $stopReason = is_string($rawStopReason) ? StopReason::tryFrom($rawStopReason) : null;

        $rawStructuredOutput = $data['structured_output'] ?? null;
        // This is present when the user asked the app for a structured answer instead of plain text only.
        /** @var array<string, mixed>|null $structuredOutput validated before app code uses it. */
        $structuredOutput = is_array($rawStructuredOutput) ? $rawStructuredOutput : null;

        $knownKeys = [
            'text', 'agent', 'session_id', 'usage', 'tools_used',
            'has_objective', 'stop_reason', 'structured_output',
            'interrupts', 'guardrail_trace', 'trace', 'message',
            'context_size', 'projected_context_size',
        ];
        /** @var array<string, mixed> $metadata validated before app code uses it. */
        $metadata = array_diff_key($data, array_flip($knownKeys));
        $rawWrapperMetadata = $data['metadata'] ?? null;
        /** @var array<string, mixed> $wrapperMetadata validated before app code uses it. */
        $wrapperMetadata = is_array($rawWrapperMetadata) ? $rawWrapperMetadata : [];

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
            message: self::parseMessage($data),
            wrapperMetadata: $wrapperMetadata,
            contextSize: self::nullableIntField($data, 'context_size'),
            projectedContextSize: self::nullableIntField($data, 'projected_context_size'),
            rawStopReason: is_string($rawStopReason) ? $rawStopReason : null,
        );
    }

    /**
     * Parse usage statistics from the raw API data.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return Usage Value returned to app code.
     */
    private static function parseUsage(array $data): Usage
    {
        /** @var array<string, mixed> $usageData validated before app code uses it. */
        $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return Usage::fromArray($usageData);
    }

    /**
     * Extract and validate the tools_used array from raw API data.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     *
     * @return list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}> Tool calls safe for app logs and UI.
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

            if (isset($tool['input']) && is_array($tool['input'])) {
                /** @var array<string, mixed> $input validated before app code uses it. */
                $input = $tool['input'];
                $entry['input'] = $input;
            }

            if (isset($tool['result']) && is_array($tool['result'])) {
                /** @var array<string, mixed> $result validated before app code uses it. */
                $result = $tool['result'];
                $entry['result'] = $result;
            }

            /** @var array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>} $entry validated before app code uses it. */
            $toolsUsed[] = $entry;
        }

        return $toolsUsed;
    }

    /**
     * Parse interrupt details from the raw API data.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     *
     * @return list<InterruptDetail> Value returned to app code.
     */
    private static function parseInterrupts(array $data): array
    {
        $rawInterrupts = $data['interrupts'] ?? null;
        // Most answers do not ask the user for approval or extra input.
        if (!is_array($rawInterrupts)) {
            return [];
        }

        $interrupts = [];
        // Each interrupt can become an approval card or follow-up question in the app.
        foreach ($rawInterrupts as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item validated before app code uses it. */
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
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return ?GuardrailTrace Value returned to app code.
     */
    private static function parseGuardrailTrace(array $data): ?GuardrailTrace
    {
        // Try top-level first; this is what the app inspects after a visible guardrail intervention.
        $raw = $data['guardrail_trace'] ?? null;

        // Fall back to nested trace.guardrail for wrappers that keep trace data grouped.
        if (!is_array($raw)) {
            $trace = $data['trace'] ?? null;
            if (is_array($trace)) {
                $raw = $trace['guardrail'] ?? null;
            }
        }

        if (!is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw validated before app code uses it. */
        return GuardrailTrace::fromArray($raw);
    }

    /**
     * Extract citation content blocks from message.content[].
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     *
     * @return list<array<string, mixed>> Citation blocks the app can render with the answer.
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
                /** @var array<string, mixed> $block validated before app code uses it. */
                $citations[] = $block;
            }
        }

        return $citations;
    }

    /**
     * Extracts the normalized raw message envelope for advanced app displays.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return ?Message Parsed message envelope, or null when it is absent.
     */
    private static function parseMessage(array $data): ?Message
    {
        $message = $data['message'] ?? null;

        $messageData = self::stringKeyedArray($message);

        return $messageData !== null ? Message::fromArray($messageData) : null;
    }

    /**
     * Reads a token count field while tolerating numeric wire variations.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @param string $key response field that may contain a token count.
     * @return ?int Token count for UI hints, or null when unavailable.
     */
    private static function nullableIntField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }

    /**
     * Keeps only string-keyed metadata so app code gets a stable map.
     *
     * @param mixed $value candidate metadata from the agent payload.
     * @return array<string, mixed>|null String-keyed metadata, or null for non-map input.
     */
    private static function stringKeyedArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}

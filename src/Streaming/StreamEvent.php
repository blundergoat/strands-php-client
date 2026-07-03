<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\Citation\Citation;

/**
 * A single typed event from an SSE stream.
 *
 * Created by StreamParser (via tryFromArray) or directly via fromArray.
 * Each property maps to a specific event type - most are null for types
 * that don't carry that field.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
class StreamEvent
{
    /**
     * Hold one typed streaming event.
     *
     * Usually built by the parser via tryFromArray(); each event maps to
     * something the UI shows as the answer streams in (a token, a tool call, …).
     *
     * @param StreamEventType  $type                The type of this event (Text, ToolUse, Complete, etc.).
     * @param string|null      $text                The text token for Text/Thinking events.
     * @param string|null      $fullText            The full accumulated text in Complete events.
     * @param string|null      $sessionId           Session ID, typically in the Complete event.
     * @param string|null      $errorCode           Error code for Error events.
     * @param string|null      $errorMessage        Human-readable error description for Error events.
     * @param array<string, mixed>  $usage          Token usage statistics.
     * @param list<array{name: string, duration_ms?: int}>  $toolsUsed  Tools the agent used.
     * @param string|null      $toolName            Tool name (for ToolUse/ToolResult events).
     * @param array<string, mixed>  $toolInput      Input/arguments passed to the tool.
     * @param string|null      $toolResult          Result/output from a tool (for ToolResult events).
     * @param bool             $hasObjective        Whether this agent had a secret objective active.
     * @param array<string, mixed>|null $citation    Citation content block for Citation events.
     * @param string|null      $reasoningSignature  Reasoning signature for ReasoningSignature events.
     * @param string|null      $stopReason          Why the agent stopped (in Complete events).
     * @param list<array<string, mixed>> $interrupts  Raw interrupt data from Complete events.
     * @param array<string, mixed>|null $guardrailTrace  Raw guardrail trace from Complete events.
     * @param int|null         $contextSize         Current context size in tokens.
     * @param int|null         $projectedContextSize Projected next-turn context size in tokens.
     */
    public function __construct(
        public readonly StreamEventType $type,
        public readonly ?string $text = null,
        public readonly ?string $fullText = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $usage = [],
        public readonly array $toolsUsed = [],
        public readonly ?string $toolName = null,
        public readonly array $toolInput = [],
        public readonly ?string $toolResult = null,
        public readonly bool $hasObjective = false,
        public readonly ?array $citation = null,
        public readonly ?string $reasoningSignature = null,
        public readonly ?string $stopReason = null,
        public readonly array $interrupts = [],
        public readonly ?array $guardrailTrace = null,
        public readonly ?int $contextSize = null,
        public readonly ?int $projectedContextSize = null,
    ) {
    }

    /**
     * Create a StreamEvent from a decoded JSON array.
     *
     * @param array<string, mixed> $data  The decoded JSON data from one SSE event.
     *
     * @return self  A new StreamEvent instance populated from the array data.
     *
     * @throws \InvalidArgumentException  If the event type is unknown or missing.
     */
    public static function fromArray(array $data): self
    {
        $rawType = self::string($data, 'type') ?? '';
        $type = StreamEventType::tryFrom($rawType);
        // Strict path: an unrecognised event type is a hard error the caller must see.
        if ($type === null) {
            throw new \InvalidArgumentException(
                sprintf('Unknown stream event type: "%s"', $rawType !== '' ? $rawType : '(missing)'),
            );
        }

        return self::buildFromArray($data, $type);
    }

    /**
     * Create a StreamEvent from a decoded JSON array, returning null for unknown types.
     *
     * Unlike fromArray(), this method is forward-compatible - it silently returns
     * null when the event type is not recognised, instead of throwing.
     *
     * @param array<string, mixed> $data  The decoded JSON data from one SSE event.
     *
     * @return self|null  A new StreamEvent, or null if the type is unknown.
     */
    public static function tryFromArray(array $data): ?self
    {
        $rawType = self::string($data, 'type') ?? '';
        $type = StreamEventType::tryFrom($rawType);
        // Forgiving path: skip an event type a newer server added but this client can't map.
        if ($type === null) {
            return null;
        }

        return self::buildFromArray($data, $type);
    }

    /**
     * Build a StreamEvent from validated data and type.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param StreamEventType $type The validated event type this data maps to.
     * @return self New instance ready for app code.
     */
    private static function buildFromArray(array $data, StreamEventType $type): self
    {
        // Field mapping note: the API uses different field names per event type.
        // Text/Thinking events send tokens in 'content' → mapped to $text.
        // Complete events send the full response in 'text' → mapped to $fullText.
        return new self(
            type: $type,
            text: self::string($data, 'content'),
            fullText: self::string($data, 'text'),
            sessionId: self::string($data, 'session_id'),
            errorCode: self::string($data, 'code'),
            errorMessage: self::string($data, 'message'),
            usage: self::arrayField($data, 'usage'),
            toolsUsed: self::toolsUsedField($data),
            toolName: self::string($data, 'tool_name'),
            toolInput: self::arrayField($data, 'tool_input'),
            toolResult: self::encodeResult($data['result'] ?? null),
            hasObjective: ($data['has_objective'] ?? false) === true,
            citation: self::nullableArrayField($data, 'citation'),
            reasoningSignature: self::string($data, 'signature'),
            stopReason: self::string($data, 'stop_reason'),
            interrupts: self::listOfArrays($data, 'interrupts'),
            guardrailTrace: self::parseGuardrailTrace($data),
            contextSize: self::nullableIntField($data, 'context_size'),
            projectedContextSize: self::nullableIntField($data, 'projected_context_size'),
        );
    }

    /**
     * Get the citation as a typed DTO, hydrated from the raw $citation array.
     *
     * @return ?Citation The citation DTO for the app to render, or null on non-citation events.
     */
    public function getCitationObject(): ?Citation
    {
        // Only Citation events carry a citation block; anything else has nothing to show.
        if ($this->citation === null) {
            return null;
        }

        return Citation::fromArray($this->citation);
    }

    /**
     * True if this is a terminal event (Complete or Error).
     *
     * @return bool true for Complete/Error events — the app's cue to stop the live stream.
     */
    public function isTerminal(): bool
    {
        return $this->type === StreamEventType::Complete || $this->type === StreamEventType::Error;
    }

    /**
     * Reads an optional string field from a stream event.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $key stream event field to read.
     * @return ?string Text the app can show, or null when absent.
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Reads a map field while shielding app code from malformed event data.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     *
     * @param string $key stream event field to read.
     * @return array<string, mixed> Map data from the event, or an empty map.
     */
    private static function arrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * Collect the tools named in a Complete event, for the app's "tools used" trail.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     *
     * @return list<array{name: string, duration_ms?: int}> Tool calls reported during the stream.
     */
    private static function toolsUsedField(array $data): array
    {
        $value = $data['tools_used'] ?? null;
        // Most events aren't Complete events, so there's usually no tool list here.
        if (!is_array($value)) {
            return [];
        }

        $tools = [];
        // Record each tool the agent used so the app can list them under the answer.
        foreach ($value as $tool) {
            // Keep only well-formed, named tool entries; ignore anything malformed.
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $entry = ['name' => $tool['name']];

                // Include the tool's duration when timed, for a per-tool latency hint.
                if (isset($tool['duration_ms']) && is_int($tool['duration_ms'])) {
                    $entry['duration_ms'] = $tool['duration_ms'];
                }

                /** @var array{name: string, duration_ms?: int} $entry validated before app code uses it. */
                $tools[] = $entry;
            }
        }

        return $tools;
    }

    /**
     * Reads an optional map field from a stream event.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     *
     * @param string $key stream event field to read.
     * @return array<string, mixed>|null Map data, or null when the event omits it.
     */
    private static function nullableArrayField(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    /**
     * Reads a list of maps while dropping malformed entries.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     *
     * @param string $key stream event field to read.
     * @return list<array<string, mixed>> List entries safe for DTO hydration.
     */
    private static function listOfArrays(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        // Missing list fields are normal for stream events that do not need follow-up UI.
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        // Keep only valid list entries before the app turns them into interrupt or trace objects.
        foreach ($value as $item) {
            // Drop any non-array entry so a malformed one can't reach the app.
            if (is_array($item)) {
                /** @var array<string, mixed> $item validated before app code uses it. */
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Extract guardrail trace from top-level or nested trace.guardrail.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     *
     * @return array<string, mixed>|null Guardrail trace for UI warnings, or null when absent.
     */
    private static function parseGuardrailTrace(array $data): ?array
    {
        $raw = self::nullableArrayField($data, 'guardrail_trace');
        // A guardrail trace is present when the UI needs to explain a safety intervention.
        if ($raw !== null) {
            return $raw;
        }

        $trace = $data['trace'] ?? null;
        // Some servers nest guardrail details under trace for the same user-facing result.
        if (is_array($trace)) {
            $guardrail = $trace['guardrail'] ?? null;
            // Use the nested guardrail block only when it's a well-formed object.
            if (is_array($guardrail)) {
                /** @var array<string, mixed> $guardrail validated before app code uses it. */
                return $guardrail;
            }
        }

        return null;
    }

    /**
     * Normalize raw tool result data into a string for the event DTO.
     *
     * @param mixed $raw Raw tool result payload; mixed is required because the wire
     * contract allows scalar, array, or null tool results.
     * @return string|null Stringified result, or null when no result exists.
     */
    private static function encodeResult(mixed $raw): ?string
    {
        // A plain string result can be shown to the user as-is.
        if (is_string($raw)) {
            return $raw;
        }

        // A structured result (array/object) is JSON-encoded so the UI can display it.
        if ($raw !== null) {
            return json_encode($raw) ?: null;
        }

        return null;
    }

    /**
     * Reads a token count field while tolerating numeric wire variations.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $key stream event field that may contain a token count.
     * @return ?int Token count for UI hints, or null when unavailable.
     */
    private static function nullableIntField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        // Already a clean integer token count — hand it straight to the app.
        if (is_int($value)) {
            return $value;
        }

        // Some wrappers report the count as a float; round to whole tokens.
        if (is_float($value)) {
            return (int) round($value);
        }

        // Others send it as a numeric string (e.g. "8192"); accept those too.
        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }
}

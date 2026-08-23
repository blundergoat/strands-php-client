<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\WireNumber;

/**
 * A single typed event from an SSE stream.
 *
 * StreamParser creates it for each live update delivered to an app callback.
 * Each event type fills only its relevant UI fields, leaving the others null or empty.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") -- the constructor mirrors every wire field of one stream event.
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
     * @param string|null $text Text token; null means this event has no text update, while an empty string is preserved.
     * @param string|null $fullText Final answer; null means this is not a Complete event, while an empty answer is preserved.
     * @param string|null $sessionId Conversation ID; null means the wrapper supplied none, while an empty string is preserved.
     * @param string|null $errorCode Error code; null means this event has no code, while an empty string is preserved.
     * @param string|null $errorMessage Error text; null means this event has no message, while an empty string is preserved.
     * @param array<string, mixed> $usage Usage values; empty means this event reported no token or timing data.
     * @param list<array{
     *     name: string,
     *     duration_ms?: int,
     *     input?: array<string, mixed>,
     *     result?: array<string, mixed>
     * }> $toolsUsed Tools shown under the answer; empty means no displayable tool activity was reported.
     * @param string|null $toolName Tool name; null means this is not a named tool event, while an empty string is preserved.
     * @param array<string, mixed> $toolInput Tool arguments; empty means no visible input was reported.
     * @param string|null $toolResult Tool output; null means unavailable, while an empty string is preserved.
     * @param bool $hasObjective Whether this agent had a secret objective active.
     * @param array<string, mixed>|null $citation Citation block; null means this is not a citation event, while an empty map is preserved.
     * @param string|null $reasoningSignature Signature; null means unavailable, while an empty string is preserved.
     * @param string|null $stopReason Terminal reason; null means the wrapper supplied none, while an empty string is preserved.
     * @param list<array<string, mixed>> $interrupts Paused actions; empty means the UI has nothing to ask the user.
     * @param array<string, mixed>|null $guardrailTrace Guardrail detail; null means none was reported, while an empty map is preserved.
     * @param int|null $contextSize Current context tokens; null means the UI should omit this capacity hint.
     * @param int|null $projectedContextSize Projected context tokens; null means the UI should omit this forecast.
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
        $rawType = self::optionalStringField($data, 'type') ?? '';
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
        $rawType = self::optionalStringField($data, 'type') ?? '';
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
     * @param array<string, mixed> $eventData Raw decoded event JSON; empty cannot produce a recognized event.
     * @param StreamEventType $eventType Validated event type this data maps to.
     * @return self New instance ready for app code.
     */
    private static function buildFromArray(array $eventData, StreamEventType $eventType): self
    {
        // Text and Thinking events map content to the token text appended by the UI.
        // Complete events map text to the full answer used for the final screen state.
        return new self(
            type: $eventType,
            text: self::optionalStringField($eventData, 'content'),
            fullText: self::optionalStringField($eventData, 'text'),
            sessionId: self::optionalStringField($eventData, 'session_id'),
            errorCode: self::optionalStringField($eventData, 'code'),
            errorMessage: self::optionalStringField($eventData, 'message'),
            usage: self::mapFieldOrEmpty($eventData, 'usage'),
            toolsUsed: self::toolsUsedField($eventData),
            toolName: self::optionalStringField($eventData, 'tool_name'),
            toolInput: self::mapFieldOrEmpty($eventData, 'tool_input'),
            toolResult: self::encodeResult($eventData['result'] ?? null),
            hasObjective: ($eventData['has_objective'] ?? false) === true,
            citation: self::optionalMapField($eventData, 'citation'),
            reasoningSignature: self::optionalStringField($eventData, 'signature'),
            stopReason: self::optionalStringField($eventData, 'stop_reason'),
            interrupts: self::listOfMaps($eventData, 'interrupts'),
            guardrailTrace: self::parseGuardrailTrace($eventData),
            contextSize: WireNumber::optionalWholeNumber($eventData, 'context_size'),
            projectedContextSize: WireNumber::optionalWholeNumber($eventData, 'projected_context_size'),
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
     * @param array<string, mixed> $eventData Raw event JSON; empty means the event carries no fields.
     * @param string $fieldName Stream event field to read.
     * @return ?string Text the app can show, or null when absent.
     */
    private static function optionalStringField(array $eventData, string $fieldName): ?string
    {
        $fieldValue = $eventData[$fieldName] ?? null;

        return is_string($fieldValue) ? $fieldValue : null;
    }

    /**
     * Reads a map field while shielding app code from malformed event data.
     *
     * @param array<string, mixed> $eventData Raw event JSON; empty means the event carries no fields.
     * @param string $fieldName Stream event field to read.
     * @return array<string, mixed> Map data from the event, or an empty map.
     */
    private static function mapFieldOrEmpty(array $eventData, string $fieldName): array
    {
        $fieldValue = $eventData[$fieldName] ?? null;

        /** @var array<string, mixed> */
        return is_array($fieldValue) ? $fieldValue : [];
    }

    /**
     * Collect the tools named in a Complete event, for the app's "tools used" trail.
     *
     * @param array<string, mixed> $eventData Raw Complete event JSON; empty means no tools were reported.
     * @return list<array{
     *     name: string,
     *     duration_ms?: int,
     *     input?: array<string, mixed>,
     *     result?: array<string, mixed>
     * }> Tools shown in the activity trail; empty means the Complete event reported no tools.
     */
    private static function toolsUsedField(array $eventData): array
    {
        $reportedTools = $eventData['tools_used'] ?? null;
        // Most events aren't Complete events, so there's usually no tool list here.
        if (!is_array($reportedTools)) {
            return [];
        }

        $tools = [];
        // Record each tool the agent used so the app can list them under the answer.
        foreach ($reportedTools as $tool) {
            // Keep only well-formed, named tool entries; ignore anything malformed.
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $toolSummary = ['name' => $tool['name']];

                // Include the tool's duration when timed, for a per-tool latency hint.
                if (isset($tool['duration_ms']) && is_int($tool['duration_ms'])) {
                    $toolSummary['duration_ms'] = $tool['duration_ms'];
                }

                // Preserve safe tool detail summaries on streams just like invoke() does.
                if (isset($tool['input']) && is_array($tool['input'])) {
                    /** @var array<string, mixed> $input validated before app code uses it. */
                    $input = $tool['input'];
                    $toolSummary['input'] = $input;
                }

                // A structured result gives the UI a safe summary without exposing an arbitrary object.
                if (isset($tool['result']) && is_array($tool['result'])) {
                    /** @var array<string, mixed> $result validated before app code uses it. */
                    $result = $tool['result'];
                    $toolSummary['result'] = $result;
                }

                /**
                 * @var array{
                 *     name: string,
                 *     duration_ms?: int,
                 *     input?: array<string, mixed>,
                 *     result?: array<string, mixed>
                 * } $toolSummary Safe for the UI activity trail.
                 */
                $tools[] = $toolSummary;
            }
        }

        return $tools;
    }

    /**
     * Reads an optional map field from a stream event.
     *
     * @param array<string, mixed> $eventData Raw event JSON; empty means the event carries no fields.
     * @param string $fieldName Stream event field to read.
     * @return array<string, mixed>|null Map data, or null when the event omits it.
     */
    private static function optionalMapField(array $eventData, string $fieldName): ?array
    {
        $fieldValue = $eventData[$fieldName] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($fieldValue) ? $fieldValue : null;
    }

    /**
     * Reads a list of maps while dropping malformed entries.
     *
     * @param array<string, mixed> $eventData Raw event JSON; empty means the event carries no fields.
     * @param string $fieldName Stream event field to read.
     * @return list<array<string, mixed>> List entries safe for DTO hydration.
     */
    private static function listOfMaps(array $eventData, string $fieldName): array
    {
        $fieldValue = $eventData[$fieldName] ?? null;
        // Missing list fields are normal for stream events that do not need follow-up UI.
        if (!is_array($fieldValue)) {
            return [];
        }

        $validMaps = [];
        // Keep only valid list entries before the app turns them into interrupt or trace objects.
        foreach ($fieldValue as $listItem) {
            // Drop any non-array entry so a malformed one can't reach the app.
            if (is_array($listItem)) {
                /** @var array<string, mixed> $listItem Validated before app code uses it. */
                $validMaps[] = $listItem;
            }
        }

        return $validMaps;
    }

    /**
     * Extract guardrail trace from top-level or nested trace.guardrail.
     *
     * @param array<string, mixed> $eventData Raw event JSON; empty means no guardrail detail was reported.
     * @return array<string, mixed>|null Guardrail trace for UI warnings, or null when absent.
     */
    private static function parseGuardrailTrace(array $eventData): ?array
    {
        $topLevelGuardrailTrace = self::optionalMapField($eventData, 'guardrail_trace');
        // A guardrail trace is present when the UI needs to explain a safety intervention.
        if ($topLevelGuardrailTrace !== null) {
            return $topLevelGuardrailTrace;
        }

        $trace = $eventData['trace'] ?? null;
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
     * @param mixed $rawToolResult Raw tool result payload; mixed is required because the wire
     * contract allows scalar, array, or null tool results.
     * @return string|null Stringified result, or null when no result exists.
     */
    private static function encodeResult(mixed $rawToolResult): ?string
    {
        // A plain string result can be shown to the user as-is.
        if (is_string($rawToolResult)) {
            return $rawToolResult;
        }

        // A structured result (array/object) is JSON-encoded so the UI can display it.
        if ($rawToolResult !== null) {
            return json_encode($rawToolResult) ?: null;
        }

        return null;
    }
}

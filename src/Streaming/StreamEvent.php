<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * A single event from an SSE stream.
 */
class StreamEvent
{
    /**
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
    ) {
    }

    /**
     * Create a StreamEvent from a decoded JSON array.
     *
     * @param array<string, mixed> $data  The decoded JSON data from one SSE event.
     *
     * @return self  A new StreamEvent instance populated from the array data.
     */
    public static function fromArray(array $data): self
    {
        $type = StreamEventType::from(self::string($data, 'type') ?? '');

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
        );
    }

    /**
     * True if this is a terminal event (Complete or Error).
     */
    public function isTerminal(): bool
    {
        return $this->type === StreamEventType::Complete || $this->type === StreamEventType::Error;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function arrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{name: string, duration_ms?: int}>
     */
    private static function toolsUsedField(array $data): array
    {
        $value = $data['tools_used'] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $tools = [];
        foreach ($value as $tool) {
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $entry = ['name' => $tool['name']];

                if (isset($tool['duration_ms']) && is_int($tool['duration_ms'])) {
                    $entry['duration_ms'] = $tool['duration_ms'];
                }

                /** @var array{name: string, duration_ms?: int} $entry */
                $tools[] = $entry;
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private static function nullableArrayField(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    private static function encodeResult(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if ($raw !== null) {
            return json_encode($raw) ?: null;
        }

        return null;
    }
}

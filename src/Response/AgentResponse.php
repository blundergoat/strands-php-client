<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Represents the complete response from a synchronous invoke() call.
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
    ) {
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

        return new self(
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            agent: is_string($data['agent'] ?? null) ? $data['agent'] : null,
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            hasObjective: ($data['has_objective'] ?? false) === true,
            usage: $usage,
            toolsUsed: self::parseToolsUsed($data),
            stopReason: $stopReason,
            structuredOutput: $structuredOutput,
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

        return new Usage(
            inputTokens: self::intField($usageData, 'input_tokens'),
            outputTokens: self::intField($usageData, 'output_tokens'),
            cacheReadInputTokens: self::intField($usageData, 'cache_read_input_tokens'),
            cacheWriteInputTokens: self::intField($usageData, 'cache_write_input_tokens'),
            latencyMs: self::intField($usageData, 'latency_ms'),
            timeToFirstByteMs: self::intField($usageData, 'time_to_first_byte_ms'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : 0;
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
}

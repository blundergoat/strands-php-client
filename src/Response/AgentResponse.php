<?php

declare(strict_types=1);

namespace Strands\Response;

/**
 * Represents the complete response from a synchronous invoke() call.
 */
class AgentResponse
{
    /**
     * @param string  $text       The agent's text response.
     * @param string|null  $agent      Agent name that handled the request.
     * @param string|null  $sessionId  Session ID for multi-turn conversations.
     * @param Usage   $usage      Token usage statistics.
     * @param list<array{name: string, duration_ms?: int}>  $toolsUsed  Tools the agent called.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $agent = null,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
    ) {
    }

    /**
     * Create from the raw JSON array returned by the /invoke endpoint.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $usageData = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $inputTokens = $usageData['input_tokens'] ?? 0;
        $outputTokens = $usageData['output_tokens'] ?? 0;

        $usage = new Usage(
            inputTokens: is_int($inputTokens) ? $inputTokens : 0,
            outputTokens: is_int($outputTokens) ? $outputTokens : 0,
        );

        return new self(
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            agent: is_string($data['agent'] ?? null) ? $data['agent'] : null,
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            usage: $usage,
            toolsUsed: self::parseToolsUsed($data),
        );
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

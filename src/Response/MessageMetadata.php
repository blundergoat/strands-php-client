<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Optional per-message extras a wrapper can attach to an agent message.
 *
 * It carries message-level usage, metrics, and app-owned fields for cost displays or diagnostics.
 * The parent message leaves it null when the wrapper sent no metadata to show.
 */
class MessageMetadata
{
    /**
     * Builds message metadata that app code can display or log.
     *
     * @param array<string, mixed> $metrics numeric or timing metadata from the wrapper.
     * @param array<string, mixed> $custom app-owned metadata from the wrapper.
     * @param ?Usage $usage Token usage for this message; null when the message carried none.
     */
    public function __construct(
        public readonly ?Usage $usage = null,
        public readonly array $metrics = [],
        public readonly array $custom = [],
    ) {
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        $rawUsage = $data['usage'] ?? null;
        $rawMetrics = $data['metrics'] ?? null;
        $rawCustom = $data['custom'] ?? null;
        $usage = self::stringKeyedArray($rawUsage);

        $metrics = self::stringKeyedArray($rawMetrics) ?? [];
        $custom = self::stringKeyedArray($rawCustom) ?? [];

        return new self(
            usage: $usage !== null ? Usage::fromArray($usage) : null,
            metrics: $metrics,
            custom: $custom,
        );
    }

    /**
     * Keeps only string-keyed metadata so app code gets a stable map.
     *
     * @param mixed $value candidate metadata from the message wrapper.
     * @return array<string, mixed>|null String-keyed metadata, or null for non-map input.
     */
    private static function stringKeyedArray(mixed $value): ?array
    {
        // No such section on the message means there is nothing for the app to read.
        if (!is_array($value)) {
            return null;
        }

        $result = [];
        // Keep only string keys so the app always gets a predictable name => value map.
        foreach ($value as $key => $item) {
            // Drop any stray numeric keys the wrapper may have mixed in.
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}

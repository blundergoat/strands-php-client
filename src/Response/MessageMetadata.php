<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Optional metadata attached to a wrapper-normalized agent message.
 */
class MessageMetadata
{
    /**
     * Builds message metadata that app code can display or log.
     *
     * @param array<string, mixed> $metrics numeric or timing metadata from the wrapper.
     * @param array<string, mixed> $custom app-owned metadata from the wrapper.
     * @param ?Usage $usage optional token usage attached to the message.
     */
    public function __construct(
        public readonly ?Usage $usage = null,
        public readonly array $metrics = [],
        public readonly array $custom = [],
    ) {
    }

    /**
     * Hydrates caller-facing data from the agent response.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
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

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Optional metadata attached to a wrapper-normalized agent message.
 */
class MessageMetadata
{
    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $custom
     */
    public function __construct(
        public readonly ?Usage $usage = null,
        public readonly array $metrics = [],
        public readonly array $custom = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
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
     * @return array<string, mixed>|null
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

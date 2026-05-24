<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Wrapper-normalized message envelope returned by an agent response.
 */
class Message
{
    /**
     * @param list<array<string, mixed>> $content
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array $content = [],
        public readonly ?MessageMetadata $metadata = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawContent = $data['content'] ?? null;
        $content = [];
        if (is_array($rawContent)) {
            foreach ($rawContent as $block) {
                if (is_array($block)) {
                    /** @var array<string, mixed> $block */
                    $content[] = $block;
                }
            }
        }

        $rawMetadata = $data['metadata'] ?? null;
        $metadata = self::stringKeyedArray($rawMetadata);

        return new self(
            role: is_string($data['role'] ?? null) ? $data['role'] : null,
            content: $content,
            metadata: $metadata !== null ? MessageMetadata::fromArray($metadata) : null,
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

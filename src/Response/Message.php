<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Wrapper-normalized message envelope returned by an agent response.
 */
class Message
{
    /**
     * Builds the message wrapper returned with the agent answer.
     *
     * @param list<array<string, mixed>> $content message blocks the app may inspect.
     * @param ?string $role role attached to the message envelope.
     * @param ?MessageMetadata $metadata optional usage and custom message metadata.
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array $content = [],
        public readonly ?MessageMetadata $metadata = null,
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
        $rawContent = $data['content'] ?? null;
        $content = [];
        if (is_array($rawContent)) {
            foreach ($rawContent as $block) {
                if (is_array($block)) {
                    /** @var array<string, mixed> $block validated before app code uses it. */
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
     * Keeps only string-keyed metadata so app code gets a stable map.
     *
     * @param mixed $value candidate metadata from the message payload.
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

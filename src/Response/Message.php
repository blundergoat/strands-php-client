<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * The raw message envelope behind an agent response, for advanced inspection.
 *
 * Callers can inspect ordered text, citation, and tool blocks plus the assistant role and message metadata.
 * Use AgentResponse::$text when only the final text is needed; absent envelope fields remain empty or null.
 */
class Message
{
    /**
     * Builds the message wrapper returned with the agent answer.
     *
     * @param ?string $role Speaker role on the envelope; null when the wrapper didn't label it.
     * @param list<array<string, mixed>> $content Message blocks the app may inspect.
     * @param ?MessageMetadata $metadata Per-message usage/custom metadata; null when none was sent.
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array $content = [],
        public readonly ?MessageMetadata $metadata = null,
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
        $rawContent = $data['content'] ?? null;
        $contentBlocks = [];
        // Preserve the wrapper's block order while dropping malformed entries.
        if (is_array($rawContent)) {
            foreach ($rawContent as $contentBlock) {
                // Keep only map-shaped blocks so callers receive a predictable list.
                if (is_array($contentBlock)) {
                    /** @var array<string, mixed> $contentBlock validated before app code uses it. */
                    $contentBlocks[] = $contentBlock;
                }
            }
        }

        $rawMetadata = $data['metadata'] ?? null;
        $metadata = self::stringKeyedArray($rawMetadata);

        return new self(
            role: is_string($data['role'] ?? null) ? $data['role'] : null,
            content: $contentBlocks,
            metadata: $metadata !== null ? MessageMetadata::fromArray($metadata) : null,
        );
    }

    /**
     * Keeps only string-keyed metadata so app code gets a stable map.
     *
     * @param mixed $candidateMetadata Candidate metadata from the message payload.
     * @return array<string, mixed>|null String-keyed metadata, or null for non-map input.
     */
    private static function stringKeyedArray(mixed $candidateMetadata): ?array
    {
        // No metadata map on the message means there is nothing for the app to read.
        if (!is_array($candidateMetadata)) {
            return null;
        }

        $stringKeyedMetadata = [];
        // Keep only string keys so the app always gets a predictable name => value map.
        foreach ($candidateMetadata as $metadataKey => $metadataValue) {
            // Drop any stray numeric keys the wrapper may have mixed in.
            if (is_string($metadataKey)) {
                $stringKeyedMetadata[$metadataKey] = $metadataValue;
            }
        }

        return $stringKeyedMetadata;
    }
}

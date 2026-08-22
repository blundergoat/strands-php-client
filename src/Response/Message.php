<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * The raw message envelope behind an agent response, for advanced displays.
 *
 * Rich UIs can render ordered text, citation, and tool blocks plus the assistant role and message metadata.
 * Use AgentResponse::$text for simple screens; absent envelope fields remain empty or null.
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
        $content = [];
        // The message body is a list of blocks the UI renders in order; skip if absent.
        if (is_array($rawContent)) {
            // Each block is one piece of the answer — a paragraph, a citation, a tool result.
            foreach ($rawContent as $block) {
                // Keep only well-formed blocks so a malformed one can't corrupt the display.
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
        // No metadata map on the message means there is nothing for the app to read.
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

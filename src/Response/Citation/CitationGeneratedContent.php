<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The slice of the agent's answer that a citation backs up.
 *
 * It lets an app highlight the exact answer text supported by a source.
 * Its fields stay null when the wrapper did not identify the generated side of the citation.
 */
final readonly class CitationGeneratedContent
{
    /**
     * Hold the slice of the answer a citation backs.
     *
     * Usually built by fromArray() from a response citation block.
     *
     * @param string|null $type Content type; null means unavailable, while an empty string is preserved.
     * @param string|null $text Supported answer text; null means unavailable, while an empty string is preserved.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $text = null,
    ) {
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data Raw generated-content map; an empty map creates all-null fields.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
        );
    }
}

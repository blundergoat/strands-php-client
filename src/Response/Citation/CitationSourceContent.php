<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The source passage a citation quotes.
 *
 * It holds the cited text and optional document name for callers that expose source details.
 * Its fields stay null when the wrapper provided only a bare reference.
 */
final readonly class CitationSourceContent
{
    /**
     * Hold the source passage a citation quotes.
     *
     * Usually built by fromArray() from a response citation block.
     *
     * @param string|null $type Content type; null means unavailable, while an empty string is preserved.
     * @param string|null $text Quoted source text; null means unavailable, while an empty string is preserved.
     * @param string|null $documentName File name; null means unavailable, while an empty string is preserved.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $text = null,
        public ?string $documentName = null,
    ) {
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data Raw source-content map; an empty map creates all-null fields.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
            documentName: is_string($data['document_name'] ?? null) ? $data['document_name'] : null,
        );
    }
}

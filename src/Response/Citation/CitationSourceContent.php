<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The source passage a citation quotes, for display beside the answer.
 *
 * Holds the cited text and, for file-based sources, the document name — what the
 * app shows when the user expands "where did this come from?". Fields stay null
 * when the wrapper provided only a bare reference.
 */
final readonly class CitationSourceContent
{
    /**
     * Hold the source passage a citation quotes.
     *
     * Usually built by fromArray() from a response citation block.
     *
     * @param string|null $type          Content type identifier.
     * @param string|null $text          The source text that was cited.
     * @param string|null $documentName  Name of the source document.
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
     * @param array<string, mixed> $data raw decoded JSON from the agent.
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

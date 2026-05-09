<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The source content that was cited.
 */
final readonly class CitationSourceContent
{
    /**
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
     * @param array<string, mixed> $data
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

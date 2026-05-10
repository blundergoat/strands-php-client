<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The generated content that references a citation.
 */
final readonly class CitationGeneratedContent
{
    /**
     * @param string|null $type  Content type identifier.
     * @param string|null $text  The generated text that references the citation.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $text = null,
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
        );
    }
}

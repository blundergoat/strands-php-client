<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * A typed citation from an agent response.
 */
final readonly class Citation
{
    public function __construct(
        public ?CitationLocation $location = null,
        public ?CitationSourceContent $sourceContent = null,
        public ?CitationGeneratedContent $generatedContent = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $locationData */
        $locationData = is_array($data['location'] ?? null) ? $data['location'] : [];
        /** @var array<string, mixed> $sourceData */
        $sourceData = is_array($data['source_content'] ?? null) ? $data['source_content'] : [];
        /** @var array<string, mixed> $generatedData */
        $generatedData = is_array($data['generated_content'] ?? null) ? $data['generated_content'] : [];

        return new self(
            location: $locationData !== [] ? CitationLocation::fromArray($locationData) : null,
            sourceContent: $sourceData !== [] ? CitationSourceContent::fromArray($sourceData) : null,
            generatedContent: $generatedData !== [] ? CitationGeneratedContent::fromArray($generatedData) : null,
        );
    }
}

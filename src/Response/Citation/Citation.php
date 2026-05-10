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
        public ?string $source = null,
        public ?string $title = null,
        public ?string $text = null,
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
        $source = self::string($data, 'source');
        $title = self::string($data, 'title');
        $text = self::string($data, 'text');

        if ($locationData === [] && ($source !== null || $title !== null)) {
            $locationData = self::flatLocationData($source, $title);
        }

        if ($sourceData === []) {
            $sourceData = self::flatSourceContentData($source, $text);
        }

        return new self(
            location: $locationData !== [] ? CitationLocation::fromArray($locationData) : null,
            sourceContent: $sourceData !== [] ? CitationSourceContent::fromArray($sourceData) : null,
            generatedContent: $generatedData !== [] ? CitationGeneratedContent::fromArray($generatedData) : null,
            source: $source,
            title: $title,
            text: $text,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function flatLocationData(?string $source, ?string $title): array
    {
        $location = [];

        if ($source !== null && self::isUrl($source)) {
            $location['url'] = $source;
        }

        if ($title !== null) {
            $location['title'] = $title;
        }

        return $location;
    }

    /**
     * @return array<string, string>
     */
    private static function flatSourceContentData(?string $source, ?string $text): array
    {
        $sourceContent = [];

        if ($text !== null) {
            $sourceContent['type'] = 'TEXT';
            $sourceContent['text'] = $text;
        }

        if ($source !== null && !self::isUrl($source)) {
            $sourceContent['type'] = 'TEXT';
            $sourceContent['document_name'] = $source;
        }

        return $sourceContent;
    }

    private static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}

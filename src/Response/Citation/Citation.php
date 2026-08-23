<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * One source the agent cited, normalized across supported wire shapes.
 *
 * It gives callers a source location, quoted text, and the part of the answer that source supports.
 * Callers can inspect both structured Wire Contract citations and legacy flat fields through the same object.
 */
final readonly class Citation
{
    /**
     * Create a normalized citation DTO.
     *
     * @param CitationLocation|null $location Structured location; null means the wrapper supplied no location details.
     * @param CitationSourceContent|null $sourceContent Source passage; null means the wrapper supplied no quoted content.
     * @param CitationGeneratedContent|null $generatedContent Answer passage; null means no generated text was linked to the source.
     * @param string|null $source Legacy source value; null means the wrapper omitted it, while an empty string is preserved.
     * @param string|null $title Display title; null means unavailable, while an empty string is preserved.
     * @param string|null $text Legacy citation text; null means unavailable, while an empty string is preserved.
     */
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
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data Raw decoded citation; an empty map creates an all-null citation callers can ignore.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $locationData validated before app code uses it. */
        $locationData = is_array($data['location'] ?? null) ? $data['location'] : [];
        /** @var array<string, mixed> $sourceContentData validated before app code uses it. */
        $sourceContentData = is_array($data['source_content'] ?? null) ? $data['source_content'] : [];
        /** @var array<string, mixed> $generatedContentData validated before app code uses it. */
        $generatedContentData = is_array($data['generated_content'] ?? null) ? $data['generated_content'] : [];
        $source = self::optionalStringField($data, 'source');
        $title = self::optionalStringField($data, 'title');
        $text = self::optionalStringField($data, 'text');

        // Older wrappers send flat source/title fields, so translate any representable location details.
        if ($locationData === [] && ($source !== null || $title !== null)) {
            $locationData = self::flatLocationData($source, $title);
        }

        // Likewise reconstruct the quoted source text when only flat fields arrived.
        if ($sourceContentData === []) {
            $sourceContentData = self::flatSourceContentData($source, $text);
        }

        return new self(
            location: $locationData !== [] ? CitationLocation::fromArray($locationData) : null,
            sourceContent: $sourceContentData !== [] ? CitationSourceContent::fromArray($sourceContentData) : null,
            generatedContent: $generatedContentData !== [] ? CitationGeneratedContent::fromArray($generatedContentData) : null,
            source: $source,
            title: $title,
            text: $text,
        );
    }

    /**
     * Reads an optional citation string without leaking malformed wire values.
     *
     * @param array<string, mixed> $citationData Raw decoded citation JSON; empty means the wrapper supplied no citation fields.
     * @param string $fieldName Citation field to read.
     * @return ?string Citation text, or null when the field is absent or malformed.
     */
    private static function optionalStringField(array $citationData, string $fieldName): ?string
    {
        $fieldValue = $citationData[$fieldName] ?? null;

        return is_string($fieldValue) ? $fieldValue : null;
    }

    /**
     * Converts legacy flat citation fields into a location block.
     *
     * @param ?string $source Raw source value from the citation; null when the citation names no source.
     * @param ?string $title Citation title; null when the source is untitled.
     * @return array<string, string> Representable legacy location data.
     */
    private static function flatLocationData(?string $source, ?string $title): array
    {
        $location = [];

        // Only absolute URLs can populate the structured location URL.
        if ($source !== null && self::isUrl($source)) {
            $location['url'] = $source;
        }

        if ($title !== null) {
            $location['title'] = $title;
        }

        return $location;
    }

    /**
     * Converts legacy flat citation fields into source content.
     *
     * @param ?string $source Raw source value from the citation; null when the citation names no source.
     * @param ?string $text Cited text; null when the citation quotes nothing.
     * @return array<string, string> Source content data for the citation DTO.
     */
    private static function flatSourceContentData(?string $source, ?string $text): array
    {
        $sourceContent = [];

        if ($text !== null) {
            $sourceContent['type'] = 'TEXT';
            $sourceContent['text'] = $text;
        }

        // A non-URL source is treated as a document name (e.g. "report.pdf").
        if ($source !== null && !self::isUrl($source)) {
            $sourceContent['type'] = 'TEXT';
            $sourceContent['document_name'] = $source;
        }

        return $sourceContent;
    }

    /**
     * Determine whether a citation source is an absolute URL.
     *
     * @param string $citationSource Citation source to test; empty is not a link and returns false.
     * @return bool True when the source is an absolute URL; false for empty, relative, or malformed text.
     */
    private static function isUrl(string $citationSource): bool
    {
        return filter_var($citationSource, FILTER_VALIDATE_URL) !== false;
    }
}

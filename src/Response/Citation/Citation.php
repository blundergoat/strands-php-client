<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * A typed citation from an agent response.
 */
final readonly class Citation
{
    /**
     * Create a normalized citation DTO.
     *
     * @param CitationLocation|null $location Structured citation location, when supplied.
     * @param CitationSourceContent|null $sourceContent Source content associated with the
     * citation, when supplied.
     * @param CitationGeneratedContent|null $generatedContent Generated content associated
     * with the citation, when supplied.
     * @param string|null $source Raw source string from the wire payload, when supplied.
     * @param string|null $title Citation title from the wire payload, when supplied.
     * @param string|null $text Citation text from the wire payload, when supplied.
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
     * Hydrates caller-facing data from the agent response.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $locationData validated before app code uses it. */
        $locationData = is_array($data['location'] ?? null) ? $data['location'] : [];
        /** @var array<string, mixed> $sourceData validated before app code uses it. */
        $sourceData = is_array($data['source_content'] ?? null) ? $data['source_content'] : [];
        /** @var array<string, mixed> $generatedData validated before app code uses it. */
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
     * Reads an optional citation string without leaking malformed wire values.
     *
     * @param array<string, mixed> $data decoded payload shape received at the client boundary.
     * @param string $key citation field to read.
     * @return ?string Citation text the app can show, or null when absent.
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Converts legacy flat citation fields into a location block.
     *
     * @param ?string $source raw source value from the citation payload.
     * @param ?string $title title shown beside the cited source.
     * @return array<string, string> Location data the app can render as citation context.
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
     * Converts legacy flat citation fields into source content.
     *
     * @param ?string $source raw source value from the citation payload.
     * @param ?string $text cited text shown beside the answer.
     * @return array<string, string> Source content data for the citation UI.
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

    /**
     * Determine whether a citation source is an absolute URL.
     *
     * @param string $value Value supplied to the method.
     * @return bool True when the value is a valid absolute URL.
     */
    private static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}

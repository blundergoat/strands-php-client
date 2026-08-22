<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * Where in a source a citation points, so the app can deep-link to it.
 *
 * It holds web links, document pages, exact content offsets, or search-result details when available.
 * Apps use the relevant fields to open or highlight a source; unrelated fields remain null.
 */
final readonly class CitationLocation
{
    /**
     * Hold where in a source a citation points.
     *
     * Usually built by fromArray() from a response citation block.
     *
     * @param string|null $type Location type; null means unknown, while an empty string is preserved.
     * @param int|null $startCharacterIndex Start character; null means the UI cannot highlight an exact range.
     * @param int|null $endCharacterIndex End character; null means the UI cannot highlight an exact range.
     * @param int|null $startChunkIndex First content chunk; null means no chunk range was reported.
     * @param int|null $endChunkIndex Last content chunk; null means no chunk range was reported.
     * @param int|null $startPageIndex First document page; null means no page range was reported.
     * @param int|null $endPageIndex Last document page; null means no page range was reported.
     * @param string|null $url Source link; null means unavailable, while an empty string is preserved.
     * @param string|null $title Source title; null means unavailable, while an empty string is preserved.
     * @param string|null $searchQuery Query that found the source; null means no search context was reported.
     * @param int|null $searchResultRank Result rank; null means no search position was reported.
     */
    public function __construct(
        public ?string $type = null,
        public ?int $startCharacterIndex = null,
        public ?int $endCharacterIndex = null,
        public ?int $startChunkIndex = null,
        public ?int $endChunkIndex = null,
        public ?int $startPageIndex = null,
        public ?int $endPageIndex = null,
        public ?string $url = null,
        public ?string $title = null,
        public ?string $searchQuery = null,
        public ?int $searchResultRank = null,
    ) {
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data Raw location map; an empty map creates all-null fields the UI can omit.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: self::optionalStringField($data, 'type'),
            startCharacterIndex: self::optionalIntegerField($data, 'start_character_index'),
            endCharacterIndex: self::optionalIntegerField($data, 'end_character_index'),
            startChunkIndex: self::optionalIntegerField($data, 'start_chunk_index'),
            endChunkIndex: self::optionalIntegerField($data, 'end_chunk_index'),
            startPageIndex: self::optionalIntegerField($data, 'start_page_index'),
            endPageIndex: self::optionalIntegerField($data, 'end_page_index'),
            url: self::optionalStringField($data, 'url'),
            title: self::optionalStringField($data, 'title'),
            searchQuery: self::optionalStringField($data, 'search_query'),
            searchResultRank: self::optionalIntegerField($data, 'search_result_rank'),
        );
    }

    /**
     * Read an optional string location field, ignoring malformed values.
     *
     * @param array<string, mixed> $locationData Raw location JSON; empty means the citation has no jump target.
     * @param string $fieldName Location field to read, such as url or title.
     * @return ?string The value for the app to display, or null when absent.
     */
    private static function optionalStringField(array $locationData, string $fieldName): ?string
    {
        $fieldValue = $locationData[$fieldName] ?? null;

        return is_string($fieldValue) ? $fieldValue : null;
    }

    /**
     * Read an optional numeric offset (page/chunk/character), tolerating wire quirks.
     *
     * @param array<string, mixed> $locationData Raw location JSON; empty means the citation has no numeric offset.
     * @param string $fieldName Location field to read, such as start_page_index.
     * @return ?int The offset the app can jump to, or null when absent.
     */
    private static function optionalIntegerField(array $locationData, string $fieldName): ?int
    {
        $fieldValue = $locationData[$fieldName] ?? null;

        // Already an integer index — hand it back directly.
        if (is_int($fieldValue)) {
            return $fieldValue;
        }

        // A float index gets rounded to the nearest whole position.
        if (is_float($fieldValue)) {
            return (int) round($fieldValue);
        }

        // A numeric string (e.g. "3") is accepted and coerced.
        if (is_string($fieldValue) && is_numeric($fieldValue)) {
            return (int) round((float) $fieldValue);
        }

        return null;
    }
}

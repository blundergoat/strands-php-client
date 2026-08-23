<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

use StrandsPhpClient\Response\WireNumber;

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
     * @param int|null $startCharacterIndex Start character; null means no exact range start was reported.
     * @param int|null $endCharacterIndex End character; null means no exact range end was reported.
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
     * @param array<string, mixed> $data Raw location map; an empty map creates all-null fields.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: self::optionalStringField($data, 'type'),
            startCharacterIndex: WireNumber::optionalWholeNumber($data, 'start_character_index'),
            endCharacterIndex: WireNumber::optionalWholeNumber($data, 'end_character_index'),
            startChunkIndex: WireNumber::optionalWholeNumber($data, 'start_chunk_index'),
            endChunkIndex: WireNumber::optionalWholeNumber($data, 'end_chunk_index'),
            startPageIndex: WireNumber::optionalWholeNumber($data, 'start_page_index'),
            endPageIndex: WireNumber::optionalWholeNumber($data, 'end_page_index'),
            url: self::optionalStringField($data, 'url'),
            title: self::optionalStringField($data, 'title'),
            searchQuery: self::optionalStringField($data, 'search_query'),
            searchResultRank: WireNumber::optionalWholeNumber($data, 'search_result_rank'),
        );
    }

    /**
     * Read an optional string location field, ignoring malformed values.
     *
     * @param array<string, mixed> $locationData Raw location JSON; empty means the citation has no jump target.
     * @param string $fieldName Location field to read, such as url or title.
     * @return ?string String value, or null when the field is absent or malformed.
     */
    private static function optionalStringField(array $locationData, string $fieldName): ?string
    {
        $fieldValue = $locationData[$fieldName] ?? null;

        return is_string($fieldValue) ? $fieldValue : null;
    }
}

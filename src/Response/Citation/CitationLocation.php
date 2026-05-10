<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * Location metadata for a citation source.
 */
final readonly class CitationLocation
{
    /**
     * @param string|null $type                  Location type (e.g. 'DOCUMENT', 'WEB', 'SEARCH_RESULT').
     * @param int|null    $startCharacterIndex    Start character offset in source content.
     * @param int|null    $endCharacterIndex      End character offset in source content.
     * @param int|null    $startChunkIndex        Start chunk index.
     * @param int|null    $endChunkIndex          End chunk index.
     * @param int|null    $startPageIndex         Start page index (documents).
     * @param int|null    $endPageIndex           End page index (documents).
     * @param string|null $url                    Source URL (web citations).
     * @param string|null $title                  Source title.
     * @param string|null $searchQuery            Search query that found this source.
     * @param int|null    $searchResultRank       Rank in search results.
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: self::string($data, 'type'),
            startCharacterIndex: self::int($data, 'start_character_index'),
            endCharacterIndex: self::int($data, 'end_character_index'),
            startChunkIndex: self::int($data, 'start_chunk_index'),
            endChunkIndex: self::int($data, 'end_chunk_index'),
            startPageIndex: self::int($data, 'start_page_index'),
            endPageIndex: self::int($data, 'end_page_index'),
            url: self::string($data, 'url'),
            title: self::string($data, 'title'),
            searchQuery: self::string($data, 'search_query'),
            searchResultRank: self::int($data, 'search_result_rank'),
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
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * Where in a source a citation points, so the app can deep-link to it.
 *
 * Depending on the source type this holds a URL and title for web results, page
 * ranges for documents, character/chunk offsets for exact spans, or the search
 * query and rank that surfaced it. Fields stay null when they don't apply.
 */
final readonly class CitationLocation
{
    /**
     * Hold where in a source a citation points.
     *
     * Usually built by fromArray() from a response citation block.
     *
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
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
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
     * Read an optional string location field, ignoring malformed values.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $key Location field to read (e.g. 'url', 'title').
     * @return ?string The value for the app to display, or null when absent.
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read an optional numeric offset (page/chunk/character), tolerating wire quirks.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $key Location field to read (e.g. 'start_page_index').
     * @return ?int The offset the app can jump to, or null when absent.
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        // Already an integer index — hand it back directly.
        if (is_int($value)) {
            return $value;
        }

        // A float index gets rounded to the nearest whole position.
        if (is_float($value)) {
            return (int) round($value);
        }

        // A numeric string (e.g. "3") is accepted and coerced.
        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }
}

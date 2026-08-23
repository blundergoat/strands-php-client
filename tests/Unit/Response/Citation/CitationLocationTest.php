<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\CitationLocation;

/**
 * Verifies document, web, search, and chunk citation locations hydrate optional indexes without unsafe coercion.
 *
 * Use these tests when changing citation position parsing or supported location types.
 * They protect the source link and highlighted range a calling application can present with an answer.
 */
class CitationLocationTest extends TestCase
{
    /**
     * Builds the page and character range for a cited document.
     *
     * @return array<string, mixed> Document location fields supplied by the agent; never empty.
     */
    private function documentLocationPayload(): array
    {
        return [
            'type' => 'DOCUMENT',
            'start_character_index' => 10,
            'end_character_index' => 50,
            'start_page_index' => 3,
            'end_page_index' => 5,
        ];
    }

    /**
     * Confirms fromArray() hydrates a document location so the app can link an answer to its source.
     *
     * @return void
     */
    public function testFromArrayDocumentLocation(): void
    {
        $locationData = $this->documentLocationPayload();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame('DOCUMENT', $location->type);
        $this->assertSame(10, $location->startCharacterIndex);
        $this->assertSame(50, $location->endCharacterIndex);
        $this->assertSame(3, $location->startPageIndex);
        $this->assertSame(5, $location->endPageIndex);
        $this->assertNull($location->url);
    }
    /**
     * Builds the URL and title for a cited web page.
     *
     * @return array<string, mixed> Web location fields supplied by the agent; never empty.
     */
    private function webLocationPayload(): array
    {
        return [
            'type' => 'WEB',
            'url' => 'https://example.com/article',
            'title' => 'Article Title',
        ];
    }


    /**
     * Confirms fromArray() hydrates a web location so the app can link an answer to its source.
     *
     * @return void
     */
    public function testFromArrayWebLocation(): void
    {
        $locationData = $this->webLocationPayload();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame('WEB', $location->type);
        $this->assertSame('https://example.com/article', $location->url);
        $this->assertSame('Article Title', $location->title);
    }
    /**
     * Builds the query and rank for a cited search result.
     *
     * @return array<string, mixed> Search-result location fields supplied by the agent; never empty.
     */
    private function searchResultLocationPayload(): array
    {
        return [
            'type' => 'SEARCH_RESULT',
            'search_query' => 'PHP OTEL',
            'search_result_rank' => 3,
            'url' => 'https://example.com/result',
        ];
    }


    /**
     * Confirms fromArray() hydrates a search-result location so the app can identify the cited result.
     *
     * @return void
     */
    public function testFromArraySearchResultLocation(): void
    {
        $locationData = $this->searchResultLocationPayload();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame('SEARCH_RESULT', $location->type);
        $this->assertSame('PHP OTEL', $location->searchQuery);
        $this->assertSame(3, $location->searchResultRank);
    }
    /**
     * Builds the chunk range for a cited passage.
     *
     * @return array<string, mixed> Chunk location fields supplied by the agent; never empty.
     */
    private function chunkLocationPayload(): array
    {
        return [
            'type' => 'CHUNK',
            'start_chunk_index' => 0,
            'end_chunk_index' => 2,
        ];
    }


    /**
     * Confirms fromArray() hydrates a content chunk so the app can highlight the cited passage.
     *
     * @return void
     */
    public function testFromArrayChunkLocation(): void
    {
        $locationData = $this->chunkLocationPayload();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame(0, $location->startChunkIndex);
        $this->assertSame(2, $location->endChunkIndex);
    }

    /**
     * Confirms fromArray() rejects non-numeric strings so apps do not highlight an invented source range.
     *
     * @return void
     */
    public function testFromArrayRejectsNonNumericStrings(): void
    {
        $locationData = [
            'start_character_index' => 'not_an_int',
        ];

        $location = CitationLocation::fromArray($locationData);

        $this->assertNull($location->startCharacterIndex);
    }
    /**
     * Builds numeric location values encoded in common JSON-compatible forms.
     *
     * @return array<string, mixed> Coercible numeric location fields; never empty.
     */
    private function coercibleNumericLocationPayload(): array
    {
        return [
            'search_result_rank' => '5',
            'start_page_index' => 2.0,
            'end_page_index' => 3.7,
        ];
    }


    /**
     * Confirms fromArray() accepts numeric strings and floats so apps can highlight the intended source range.
     *
     * @return void
     */
    public function testFromArrayAcceptsNumericStringsAndFloats(): void
    {
        $locationData = $this->coercibleNumericLocationPayload();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame(5, $location->searchResultRank);
        $this->assertSame(2, $location->startPageIndex);
        $this->assertSame(4, $location->endPageIndex);
    }

    /**
     * Confirms fractional numeric strings and floats use nearest-integer rounding so apps can highlight the intended source range.
     *
     * @return void
     */
    public function testFromArrayRoundsFractionalIndexesToNearestInteger(): void
    {
        $location = CitationLocation::fromArray([
            'start_character_index' => 2.2,
            'end_character_index' => 2.7,
            'start_chunk_index' => '3.2',
            'end_chunk_index' => '3.7',
        ]);

        $this->assertSame(2, $location->startCharacterIndex);
        $this->assertSame(3, $location->endCharacterIndex);
        $this->assertSame(3, $location->startChunkIndex);
        $this->assertSame(4, $location->endChunkIndex);
    }

    /**
     * Confirms fromArray() gives an empty location safe defaults so the app can omit unavailable source details.
     *
     * @return void
     */
    public function testFromArrayEmptyData(): void
    {
        $location = CitationLocation::fromArray([]);

        $this->assertNull($location->type);
        $this->assertNull($location->startCharacterIndex);
        $this->assertNull($location->endCharacterIndex);
        $this->assertNull($location->url);
        $this->assertNull($location->title);
    }
}

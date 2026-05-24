<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\CitationLocation;

class CitationLocationTest extends TestCase
{
    /**
     * Verifies that from array document location.
     *
     * @return void
     */
    public function testFromArrayDocumentLocation(): void
    {
        $data = [
            'type' => 'DOCUMENT',
            'start_character_index' => 10,
            'end_character_index' => 50,
            'start_page_index' => 3,
            'end_page_index' => 5,
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertSame('DOCUMENT', $location->type);
        $this->assertSame(10, $location->startCharacterIndex);
        $this->assertSame(50, $location->endCharacterIndex);
        $this->assertSame(3, $location->startPageIndex);
        $this->assertSame(5, $location->endPageIndex);
        $this->assertNull($location->url);
    }

    /**
     * Verifies that from array web location.
     *
     * @return void
     */
    public function testFromArrayWebLocation(): void
    {
        $data = [
            'type' => 'WEB',
            'url' => 'https://example.com/article',
            'title' => 'Article Title',
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertSame('WEB', $location->type);
        $this->assertSame('https://example.com/article', $location->url);
        $this->assertSame('Article Title', $location->title);
    }

    /**
     * Verifies that from array search result location.
     *
     * @return void
     */
    public function testFromArraySearchResultLocation(): void
    {
        $data = [
            'type' => 'SEARCH_RESULT',
            'search_query' => 'PHP OTEL',
            'search_result_rank' => 3,
            'url' => 'https://example.com/result',
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertSame('SEARCH_RESULT', $location->type);
        $this->assertSame('PHP OTEL', $location->searchQuery);
        $this->assertSame(3, $location->searchResultRank);
    }

    /**
     * Verifies that from array chunk location.
     *
     * @return void
     */
    public function testFromArrayChunkLocation(): void
    {
        $data = [
            'type' => 'CHUNK',
            'start_chunk_index' => 0,
            'end_chunk_index' => 2,
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertSame(0, $location->startChunkIndex);
        $this->assertSame(2, $location->endChunkIndex);
    }

    /**
     * Verifies that from array rejects non numeric strings.
     *
     * @return void
     */
    public function testFromArrayRejectsNonNumericStrings(): void
    {
        $data = [
            'start_character_index' => 'not_an_int',
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertNull($location->startCharacterIndex);
    }

    /**
     * Verifies that from array accepts numeric strings and floats.
     *
     * @return void
     */
    public function testFromArrayAcceptsNumericStringsAndFloats(): void
    {
        $data = [
            'search_result_rank' => '5',
            'start_page_index' => 2.0,
            'end_page_index' => 3.7,
        ];

        $location = CitationLocation::fromArray($data);

        $this->assertSame(5, $location->searchResultRank);
        $this->assertSame(2, $location->startPageIndex);
        $this->assertSame(4, $location->endPageIndex);
    }

    /**
     * Verifies that from array empty data.
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

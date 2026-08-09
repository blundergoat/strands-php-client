<?php

declare(strict_types=1);

/**
 * Tests caller-visible Citation Location behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\CitationLocation;

/**
 * Verifies Citation Location behavior that application users rely on.
 */
class CitationLocationTest extends TestCase
{
    /**
     * Data fixture for testFromArrayDocumentLocation().
     *
     * @return array<string, mixed> Scenarios that keep from array document location behavior stable for app callers.
     */
    private function dataForFromArrayDocumentLocation(): array
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
     * Verifies that from array document location.
     *
     * @return void
     */
    public function testFromArrayDocumentLocation(): void
    {
        $data = $this->dataForFromArrayDocumentLocation();

        $location = CitationLocation::fromArray($data);

        $this->assertSame('DOCUMENT', $location->type);
        $this->assertSame(10, $location->startCharacterIndex);
        $this->assertSame(50, $location->endCharacterIndex);
        $this->assertSame(3, $location->startPageIndex);
        $this->assertSame(5, $location->endPageIndex);
        $this->assertNull($location->url);
    }
    /**
     * Data fixture for testFromArrayWebLocation().
     *
     * @return array<string, mixed> Scenarios that keep from array web location behavior stable for app callers.
     */
    private function dataForFromArrayWebLocation(): array
    {
        return [
            'type' => 'WEB',
            'url' => 'https://example.com/article',
            'title' => 'Article Title',
        ];
    }


    /**
     * Verifies that from array web location.
     *
     * @return void
     */
    public function testFromArrayWebLocation(): void
    {
        $data = $this->dataForFromArrayWebLocation();

        $location = CitationLocation::fromArray($data);

        $this->assertSame('WEB', $location->type);
        $this->assertSame('https://example.com/article', $location->url);
        $this->assertSame('Article Title', $location->title);
    }
    /**
     * Data fixture for testFromArraySearchResultLocation().
     *
     * @return array<string, mixed> Scenarios that keep from array search result location behavior stable for app callers.
     */
    private function dataForFromArraySearchResultLocation(): array
    {
        return [
            'type' => 'SEARCH_RESULT',
            'search_query' => 'PHP OTEL',
            'search_result_rank' => 3,
            'url' => 'https://example.com/result',
        ];
    }


    /**
     * Verifies that from array search result location.
     *
     * @return void
     */
    public function testFromArraySearchResultLocation(): void
    {
        $data = $this->dataForFromArraySearchResultLocation();

        $location = CitationLocation::fromArray($data);

        $this->assertSame('SEARCH_RESULT', $location->type);
        $this->assertSame('PHP OTEL', $location->searchQuery);
        $this->assertSame(3, $location->searchResultRank);
    }
    /**
     * Data fixture for testFromArrayChunkLocation().
     *
     * @return array<string, mixed> Scenarios that keep from array chunk location behavior stable for app callers.
     */
    private function dataForFromArrayChunkLocation(): array
    {
        return [
            'type' => 'CHUNK',
            'start_chunk_index' => 0,
            'end_chunk_index' => 2,
        ];
    }


    /**
     * Verifies that from array chunk location.
     *
     * @return void
     */
    public function testFromArrayChunkLocation(): void
    {
        $data = $this->dataForFromArrayChunkLocation();

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
     * Data fixture for testFromArrayAcceptsNumericStringsAndFloats().
     *
     * @return array<string, mixed> Scenarios that keep from array accepts numeric strings and floats behavior stable for app callers.
     */
    private function dataForFromArrayAcceptsNumericStringsAndFloats(): array
    {
        return [
            'search_result_rank' => '5',
            'start_page_index' => 2.0,
            'end_page_index' => 3.7,
        ];
    }


    /**
     * Verifies that from array accepts numeric strings and floats.
     *
     * @return void
     */
    public function testFromArrayAcceptsNumericStringsAndFloats(): void
    {
        $data = $this->dataForFromArrayAcceptsNumericStringsAndFloats();

        $location = CitationLocation::fromArray($data);

        $this->assertSame(5, $location->searchResultRank);
        $this->assertSame(2, $location->startPageIndex);
        $this->assertSame(4, $location->endPageIndex);
    }

    /**
     * Verifies that fractional numeric strings and floats use nearest-integer rounding.
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

<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Citation Location behavior for app integrations.
 *
 * Use this file when changing Citation Location or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\CitationLocation;

/**
 * Exercises Citation Location through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class CitationLocationTest extends TestCase
{
    /**
     * Data fixture for testFromArrayDocumentLocation().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
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
     * Confirms fromArray() hydrates a document location so the app can link an answer to its source.
     *
     * @return void
     */
    public function testFromArrayDocumentLocation(): void
    {
        $locationData = $this->dataForFromArrayDocumentLocation();

        $location = CitationLocation::fromArray($locationData);

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
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
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
     * Confirms fromArray() hydrates a web location so the app can link an answer to its source.
     *
     * @return void
     */
    public function testFromArrayWebLocation(): void
    {
        $locationData = $this->dataForFromArrayWebLocation();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame('WEB', $location->type);
        $this->assertSame('https://example.com/article', $location->url);
        $this->assertSame('Article Title', $location->title);
    }
    /**
     * Data fixture for testFromArraySearchResultLocation().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
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
     * Confirms fromArray() hydrates a search-result location so the app can identify the cited result.
     *
     * @return void
     */
    public function testFromArraySearchResultLocation(): void
    {
        $locationData = $this->dataForFromArraySearchResultLocation();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame('SEARCH_RESULT', $location->type);
        $this->assertSame('PHP OTEL', $location->searchQuery);
        $this->assertSame(3, $location->searchResultRank);
    }
    /**
     * Data fixture for testFromArrayChunkLocation().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
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
     * Confirms fromArray() hydrates a content chunk so the app can highlight the cited passage.
     *
     * @return void
     */
    public function testFromArrayChunkLocation(): void
    {
        $locationData = $this->dataForFromArrayChunkLocation();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame(0, $location->startChunkIndex);
        $this->assertSame(2, $location->endChunkIndex);
    }

    /**
     * Confirms fromArray() rejects non numeric strings so the app renders trustworthy answer details.
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
     * Data fixture for testFromArrayAcceptsNumericStringsAndFloats().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
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
     * Confirms fromArray() accepts numeric strings and floats so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayAcceptsNumericStringsAndFloats(): void
    {
        $locationData = $this->dataForFromArrayAcceptsNumericStringsAndFloats();

        $location = CitationLocation::fromArray($locationData);

        $this->assertSame(5, $location->searchResultRank);
        $this->assertSame(2, $location->startPageIndex);
        $this->assertSame(4, $location->endPageIndex);
    }

    /**
     * Confirms fractional numeric strings and floats use nearest-integer rounding so the app renders trustworthy answer details.
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

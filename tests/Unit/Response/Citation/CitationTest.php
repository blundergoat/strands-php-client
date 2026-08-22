<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Citation behavior for app integrations.
 *
 * Use this file when changing Citation or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\Citation\CitationGeneratedContent;
use StrandsPhpClient\Response\Citation\CitationLocation;
use StrandsPhpClient\Response\Citation\CitationSourceContent;

/**
 * Exercises Citation through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class CitationTest extends TestCase
{
    /**
     * Data fixture for testFromArrayWithFullData().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayWithFullData(): array
    {
        return [
            'location' => [
                'type' => 'DOCUMENT',
                'start_character_index' => 0,
                'end_character_index' => 42,
                'start_page_index' => 1,
                'end_page_index' => 2,
                'url' => 'https://example.com/doc',
                'title' => 'Test Document',
            ],
            'source_content' => [
                'type' => 'TEXT',
                'text' => 'The source text',
                'document_name' => 'report.pdf',
            ],
            'generated_content' => [
                'type' => 'TEXT',
                'text' => 'The generated text',
            ],
        ];
    }

    /**
     * Confirms fromArray() hydrates complete citation data so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayWithFullData(): void
    {
        $citationData = $this->dataForFromArrayWithFullData();

        $citation = Citation::fromArray($citationData);

        $this->assertInstanceOf(CitationLocation::class, $citation->location);
        $this->assertSame('DOCUMENT', $citation->location->type);
        $this->assertSame(0, $citation->location->startCharacterIndex);
        $this->assertSame(42, $citation->location->endCharacterIndex);
        $this->assertSame('https://example.com/doc', $citation->location->url);
        $this->assertSame('Test Document', $citation->location->title);

        $this->assertInstanceOf(CitationSourceContent::class, $citation->sourceContent);
        $this->assertSame('TEXT', $citation->sourceContent->type);
        $this->assertSame('The source text', $citation->sourceContent->text);
        $this->assertSame('report.pdf', $citation->sourceContent->documentName);

        $this->assertInstanceOf(CitationGeneratedContent::class, $citation->generatedContent);
        $this->assertSame('TEXT', $citation->generatedContent->type);
        $this->assertSame('The generated text', $citation->generatedContent->text);
    }
    /**
     * Data fixture for testFromArrayWithPartialData().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayWithPartialData(): array
    {
        return [
            'location' => [
                'type' => 'WEB',
                'url' => 'https://example.com',
            ],
        ];
    }


    /**
     * Confirms fromArray() accepts partial citation data so the app can render the details that are available.
     *
     * @return void
     */
    public function testFromArrayWithPartialData(): void
    {
        $citationData = $this->dataForFromArrayWithPartialData();

        $citation = Citation::fromArray($citationData);

        $this->assertNotNull($citation->location);
        $this->assertSame('WEB', $citation->location->type);
        $this->assertSame('https://example.com', $citation->location->url);
        $this->assertNull($citation->location->startCharacterIndex);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
    }
    /**
     * Data fixture for testFromArrayPreservesFlatCitationData().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayPreservesFlatCitationData(): array
    {
        return [
            'source' => 'https://example.com/docs',
            'title' => 'Official Documentation',
            'text' => 'the answer is 42',
        ];
    }


    /**
     * Confirms fromArray() preserves flat citation data so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayPreservesFlatCitationData(): void
    {
        $citationData = $this->dataForFromArrayPreservesFlatCitationData();

        $citation = Citation::fromArray($citationData);

        $this->assertSame('https://example.com/docs', $citation->source);
        $this->assertSame('Official Documentation', $citation->title);
        $this->assertSame('the answer is 42', $citation->text);
        $this->assertSame('https://example.com/docs', $citation->location?->url);
        $this->assertSame('Official Documentation', $citation->location?->title);
        $this->assertSame('the answer is 42', $citation->sourceContent?->text);
    }

    /**
     * Confirms fromArray() maps flat document source to source content so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayMapsFlatDocumentSourceToSourceContent(): void
    {
        $citation = Citation::fromArray([
            'source' => 'doc.pdf',
            'text' => 'relevant excerpt',
        ]);

        $this->assertNull($citation->location);
        $this->assertSame('doc.pdf', $citation->source);
        $this->assertSame('doc.pdf', $citation->sourceContent?->documentName);
        $this->assertSame('relevant excerpt', $citation->sourceContent?->text);
    }

    /**
     * Confirms either flat source field can construct a citation location so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayBuildsLocationFromSourceOnlyOrTitleOnly(): void
    {
        $sourceOnly = Citation::fromArray(['source' => 'https://example.com/source']);
        $titleOnly = Citation::fromArray(['title' => 'Untitled source']);

        $this->assertSame('https://example.com/source', $sourceOnly->location?->url);
        $this->assertSame('Untitled source', $titleOnly->location?->title);
    }

    /**
     * Confirms explicit structured location data wins over legacy flat fields so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayDoesNotReplaceStructuredLocationWithFlatFields(): void
    {
        $citation = Citation::fromArray([
            'location' => [
                'type' => 'WEB',
                'url' => 'https://example.com/structured',
                'title' => 'Structured title',
            ],
            'source' => 'https://example.com/flat',
            'title' => 'Flat title',
        ]);

        $this->assertSame('WEB', $citation->location?->type);
        $this->assertSame('https://example.com/structured', $citation->location?->url);
        $this->assertSame('Structured title', $citation->location?->title);
    }

    /**
     * Confirms fromArray() gives empty citation data safe defaults so the app can omit unavailable details.
     *
     * @return void
     */
    public function testFromArrayWithEmptyData(): void
    {
        $citation = Citation::fromArray([]);

        $this->assertNull($citation->location);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
        $this->assertNull($citation->source);
        $this->assertNull($citation->title);
        $this->assertNull($citation->text);
    }
}

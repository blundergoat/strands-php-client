<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response\Citation;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\Citation\CitationGeneratedContent;
use StrandsPhpClient\Response\Citation\CitationLocation;
use StrandsPhpClient\Response\Citation\CitationSourceContent;

class CitationTest extends TestCase
{
    public function testFromArrayWithFullData(): void
    {
        $data = [
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

        $citation = Citation::fromArray($data);

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

    public function testFromArrayWithPartialData(): void
    {
        $data = [
            'location' => [
                'type' => 'WEB',
                'url' => 'https://example.com',
            ],
        ];

        $citation = Citation::fromArray($data);

        $this->assertNotNull($citation->location);
        $this->assertSame('WEB', $citation->location->type);
        $this->assertSame('https://example.com', $citation->location->url);
        $this->assertNull($citation->location->startCharacterIndex);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $citation = Citation::fromArray([]);

        $this->assertNull($citation->location);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
    }
}

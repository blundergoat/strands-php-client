<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class StreamEventCitationTest extends TestCase
{
    public function testGetCitationObjectReturnsTypedCitation(): void
    {
        $event = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'location' => ['type' => 'WEB', 'url' => 'https://example.com'],
                'source_content' => ['type' => 'TEXT', 'text' => 'cited text'],
                'generated_content' => ['type' => 'TEXT', 'text' => 'generated text'],
            ],
        );

        $citation = $event->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('WEB', $citation->location?->type);
        $this->assertSame('https://example.com', $citation->location?->url);
        $this->assertSame('cited text', $citation->sourceContent?->text);
        $this->assertSame('generated text', $citation->generatedContent?->text);
    }

    public function testGetCitationObjectReturnsNullWhenNoCitation(): void
    {
        $event = new StreamEvent(type: StreamEventType::Text, text: 'hello');

        $this->assertNull($event->getCitationObject());
    }

    public function testGetCitationObjectHandlesPartialData(): void
    {
        $event = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'location' => ['type' => 'DOCUMENT', 'start_page_index' => 3],
            ],
        );

        $citation = $event->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('DOCUMENT', $citation->location?->type);
        $this->assertSame(3, $citation->location?->startPageIndex);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
    }

    public function testGetCitationObjectPreservesFlatCitationData(): void
    {
        $event = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'source' => 'doc.pdf',
                'page' => 3,
                'text' => 'relevant excerpt',
            ],
        );

        $citation = $event->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('doc.pdf', $citation->source);
        $this->assertSame('relevant excerpt', $citation->text);
        $this->assertSame('doc.pdf', $citation->sourceContent?->documentName);
        $this->assertSame('relevant excerpt', $citation->sourceContent?->text);
    }
}

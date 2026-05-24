<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class StreamEventCitationTest extends TestCase
{
    /**
     * Verifies that get citation object returns typed citation.
     *
     * @return void
     */
    public function testGetCitationObjectReturnsTypedCitation(): void
    {
        $streamEvent = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'location' => ['type' => 'WEB', 'url' => 'https://example.com'],
                'source_content' => ['type' => 'TEXT', 'text' => 'cited text'],
                'generated_content' => ['type' => 'TEXT', 'text' => 'generated text'],
            ],
        );

        $citation = $streamEvent->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('WEB', $citation->location?->type);
        $this->assertSame('https://example.com', $citation->location?->url);
        $this->assertSame('cited text', $citation->sourceContent?->text);
        $this->assertSame('generated text', $citation->generatedContent?->text);
    }

    /**
     * Verifies that get citation object returns null when no citation.
     *
     * @return void
     */
    public function testGetCitationObjectReturnsNullWhenNoCitation(): void
    {
        $streamEvent = new StreamEvent(type: StreamEventType::Text, text: 'hello');

        $this->assertNull($streamEvent->getCitationObject());
    }

    /**
     * Verifies that get citation object handles partial data.
     *
     * @return void
     */
    public function testGetCitationObjectHandlesPartialData(): void
    {
        $streamEvent = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'location' => ['type' => 'DOCUMENT', 'start_page_index' => 3],
            ],
        );

        $citation = $streamEvent->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('DOCUMENT', $citation->location?->type);
        $this->assertSame(3, $citation->location?->startPageIndex);
        $this->assertNull($citation->sourceContent);
        $this->assertNull($citation->generatedContent);
    }

    /**
     * Verifies that get citation object preserves flat citation data.
     *
     * @return void
     */
    public function testGetCitationObjectPreservesFlatCitationData(): void
    {
        $streamEvent = new StreamEvent(
            type: StreamEventType::Citation,
            citation: [
                'source' => 'doc.pdf',
                'text' => 'relevant excerpt',
            ],
        );

        $citation = $streamEvent->getCitationObject();

        $this->assertInstanceOf(Citation::class, $citation);
        $this->assertSame('doc.pdf', $citation->source);
        $this->assertSame('relevant excerpt', $citation->text);
        $this->assertSame('doc.pdf', $citation->sourceContent?->documentName);
        $this->assertSame('relevant excerpt', $citation->sourceContent?->text);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Verifies citation stream events expose a typed Citation while preserving partial and legacy flat data.
 *
 * Use these tests when changing StreamEvent citation hydration or compatibility fields.
 * They protect source details shown while an answer is still streaming.
 */
class StreamEventCitationTest extends TestCase
{
    /**
     * Confirms getCitationObject() returns typed citation so apps receive reliable live updates.
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
     * Confirms getCitationObject() returns null when no citation so apps receive reliable live updates.
     *
     * @return void
     */
    public function testGetCitationObjectReturnsNullWhenNoCitation(): void
    {
        $streamEvent = new StreamEvent(type: StreamEventType::Text, text: 'hello');

        $this->assertNull($streamEvent->getCitationObject());
    }

    /**
     * Confirms getCitationObject() handles partial data so apps receive reliable live updates.
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
     * Confirms getCitationObject() preserves flat citation data so apps receive reliable live updates.
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

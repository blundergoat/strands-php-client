<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Tests\Fixtures\Compatibility\V1StreamParserExtension;

/**
 * Verifies StreamParser keeps construction patterns that applications could use before 1.5.
 *
 * It protects subclasses with their own constructor from failing when the first network chunk arrives.
 * Use this suite whenever parser state initialization or constructor behavior changes.
 */
final class StreamParserCompatibilityTest extends TestCase
{
    /**
     * Confirms a 1.4-style subclass can parse its first complete event without parent initialization.
     * Use it to protect applications that wrap StreamParser with their own constructor.
     *
     * @return void
     */
    public function testSubclassConstructorWithoutParentInitializationCanFeedEvent(): void
    {
        $streamParser = new V1StreamParserExtension();

        $events = $streamParser->feed("data: {\"type\":\"text\",\"content\":\"hello\"}\n\n");

        $this->assertCount(1, $events);
        $this->assertSame(StreamEventType::Text, $events[0]->type);
        $this->assertSame('hello', $events[0]->text);
        $this->assertSame(0, $streamParser->getSkippedEvents());
    }
}

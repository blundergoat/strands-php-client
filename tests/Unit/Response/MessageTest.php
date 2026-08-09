<?php

declare(strict_types=1);

/**
 * Tests defensive parsing of raw message content blocks.
 */

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Message;

/**
 * Verifies Message behavior that application users rely on.
 */
final class MessageTest extends TestCase
{
    /**
     * Verifies that malformed content entries are ignored without dropping valid blocks.
     *
     * @return void
     */
    public function testFromArrayKeepsOnlyArrayContentBlocks(): void
    {
        $message = Message::fromArray([
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'First'],
                'malformed',
                ['type' => 'text', 'text' => 'Second'],
                42,
            ],
        ]);

        $this->assertSame('assistant', $message->role);
        $this->assertSame([
            ['type' => 'text', 'text' => 'First'],
            ['type' => 'text', 'text' => 'Second'],
        ], $message->content);
    }
}

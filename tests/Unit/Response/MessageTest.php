<?php

declare(strict_types=1);

/**
 * Exercises defensive parsing of raw message content blocks.
 *
 * Use this file when changing how wire messages become user-visible content.
 * It protects the UI from malformed or incomplete response blocks.
 */

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Message;

/**
 * Exercises Message through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
final class MessageTest extends TestCase
{
    /**
     * Confirms malformed content entries are ignored without dropping valid blocks so the app renders trustworthy answer details.
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

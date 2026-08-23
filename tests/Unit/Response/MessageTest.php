<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\Message;

/**
 * Verifies message hydration keeps only structured content blocks from an agent response.
 *
 * Use this test when changing Message::fromArray() or wire-content filtering.
 * It protects calling applications from treating malformed scalar content as renderable blocks.
 */
final class MessageTest extends TestCase
{
    /**
     * Confirms malformed content entries are ignored without dropping valid blocks so apps display only valid content blocks.
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

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\NullAuth;

class NullAuthTest extends TestCase
{
    /**
     * Verifies that returns headers unmodified.
     *
     * @return void
     */
    public function testReturnsHeadersUnmodified(): void
    {
        $nullAuth = new NullAuth();
        $headers = ['Content-Type' => 'application/json'];

        $result = $nullAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame($headers, $result);
    }

    /**
     * Verifies that preserves all multiple headers.
     *
     * @return void
     */
    public function testPreservesAllMultipleHeaders(): void
    {
        $nullAuth = new NullAuth();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $nullAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertCount(2, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }
}

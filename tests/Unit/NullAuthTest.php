<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\NullAuth;

class NullAuthTest extends TestCase
{
    public function testReturnsHeadersUnmodified(): void
    {
        $auth = new NullAuth();
        $headers = ['Content-Type' => 'application/json'];

        $result = $auth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame($headers, $result);
    }

    public function testPreservesAllMultipleHeaders(): void
    {
        $auth = new NullAuth();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $auth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertCount(2, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }
}

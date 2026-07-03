<?php

declare(strict_types=1);

/**
 * Tests caller-visible Null Auth behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\NullAuth;

/**
 * Verifies Null Auth behavior that application users rely on.
 */
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
     * Test fixture for testPreservesAllMultipleHeaders().
     *
     * @return array<string, mixed> Scenarios that keep preserves all multiple headers behavior stable for app callers.
     */
    private function dataForPreservesAllMultipleHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];
    }


    /**
     * Verifies that preserves all multiple headers.
     *
     * @return void
     */
    public function testPreservesAllMultipleHeaders(): void
    {
        $nullAuth = new NullAuth();
        $headers = $this->dataForPreservesAllMultipleHeaders();

        $result = $nullAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertCount(2, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }
}

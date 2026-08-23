<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\NullAuth;

/**
 * Verifies unauthenticated requests preserve every caller header without adding credentials.
 *
 * Use these tests when changing the no-auth strategy or shared header handling.
 * They protect agents that rely on network-level trust or application-supplied headers.
 */
class NullAuthTest extends TestCase
{
    /**
     * Confirms headers are returned unmodified so unauthenticated gateways receive exactly the application's headers.
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
     * Builds the application headers that no-auth mode must pass through unchanged.
     *
     * @return array<string, string> Non-empty request headers supplied by the calling application.
     */
    private function applicationRequestHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];
    }


    /**
     * Confirms all caller headers are preserved so unauthenticated gateways receive the intended request.
     *
     * @return void
     */
    public function testPreservesAllMultipleHeaders(): void
    {
        $nullAuth = new NullAuth();
        $headers = $this->applicationRequestHeaders();

        $result = $nullAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertCount(2, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }
}

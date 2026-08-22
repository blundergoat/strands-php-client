<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Null Auth behavior for app integrations.
 *
 * Use this file when changing Null Auth or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\NullAuth;

/**
 * Exercises Null Auth through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class NullAuthTest extends TestCase
{
    /**
     * Confirms headers are returned unmodified so authenticated requests reach the agent with the intended headers.
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
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForPreservesAllMultipleHeaders(): array
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
        $headers = $this->dataForPreservesAllMultipleHeaders();

        $result = $nullAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertCount(2, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }
}

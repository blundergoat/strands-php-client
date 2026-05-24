<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\ApiKeyAuth;

class ApiKeyAuthTest extends TestCase
{
    /**
     * Verifies that default bearer auth.
     *
     * @return void
     */
    public function testDefaultBearerAuth(): void
    {
        $auth = new ApiKeyAuth('sk-abc123');
        $headers = ['Content-Type' => 'application/json'];

        $result = $auth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame('Bearer sk-abc123', $result['Authorization']);
        $this->assertSame('application/json', $result['Content-Type']);
    }

    /**
     * Verifies that custom header name.
     *
     * @return void
     */
    public function testCustomHeaderName(): void
    {
        $auth = new ApiKeyAuth('my-key', headerName: 'X-API-Key', valuePrefix: '');
        $headers = [];

        $result = $auth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame('my-key', $result['X-API-Key']);
        $this->assertArrayNotHasKey('Authorization', $result);
    }

    /**
     * Verifies that custom prefix.
     *
     * @return void
     */
    public function testCustomPrefix(): void
    {
        $auth = new ApiKeyAuth('token123', valuePrefix: 'Token ');

        $result = $auth->authenticate([], 'POST', 'http://example.com', '');

        $this->assertSame('Token token123', $result['Authorization']);
    }

    /**
     * Verifies that preserves existing headers.
     *
     * @return void
     */
    public function testPreservesExistingHeaders(): void
    {
        $auth = new ApiKeyAuth('key');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $auth->authenticate($headers, 'POST', 'http://localhost', '{}');

        $this->assertCount(3, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
        $this->assertSame('Bearer key', $result['Authorization']);
    }
}

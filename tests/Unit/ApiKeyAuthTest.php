<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\ApiKeyAuth;

/**
 * Verifies API-key authentication adds the header name and value prefix configured by the calling app.
 *
 * Use these tests when changing default bearer behavior or custom authentication headers.
 * They protect existing request headers while ensuring the agent receives usable credentials.
 */
class ApiKeyAuthTest extends TestCase
{
    /**
     * Confirms the default bearer authentication is applied so the gateway receives the authentication format configured by the application.
     *
     * @return void
     */
    public function testDefaultBearerAuth(): void
    {
        $apiKeyAuth = new ApiKeyAuth('sk-abc123');
        $headers = ['Content-Type' => 'application/json'];

        $result = $apiKeyAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame('Bearer sk-abc123', $result['Authorization']);
        $this->assertSame('application/json', $result['Content-Type']);
    }

    /**
     * Confirms a custom API-key header name is applied so the gateway receives the authentication format configured by the application.
     *
     * @return void
     */
    public function testCustomHeaderName(): void
    {
        $apiKeyAuth = new ApiKeyAuth('my-key', headerName: 'X-API-Key', valuePrefix: '');
        $headers = [];

        $result = $apiKeyAuth->authenticate($headers, 'POST', 'http://localhost/invoke', '{}');

        $this->assertSame('my-key', $result['X-API-Key']);
        $this->assertArrayNotHasKey('Authorization', $result);
    }

    /**
     * Confirms a custom API-key prefix is applied so the gateway receives the authentication format configured by the application.
     *
     * @return void
     */
    public function testCustomPrefix(): void
    {
        $apiKeyAuth = new ApiKeyAuth('token123', valuePrefix: 'Token ');

        $result = $apiKeyAuth->authenticate([], 'POST', 'http://example.com', '');

        $this->assertSame('Token token123', $result['Authorization']);
    }

    /**
     * Confirms existing headers are preserved so the gateway receives the authentication format configured by the application.
     *
     * @return void
     */
    public function testPreservesExistingHeaders(): void
    {
        $apiKeyAuth = new ApiKeyAuth('key');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $apiKeyAuth->authenticate($headers, 'POST', 'http://localhost', '{}');

        $this->assertCount(3, $result);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
        $this->assertSame('Bearer key', $result['Authorization']);
    }
}

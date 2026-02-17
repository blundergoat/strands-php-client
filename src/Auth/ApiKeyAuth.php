<?php

declare(strict_types=1);

namespace StrandsPhpClient\Auth;

/**
 * API key authentication strategy.
 *
 * Adds an API key to outgoing requests via a configurable HTTP header.
 * Defaults to "Authorization: Bearer <key>".
 */
class ApiKeyAuth implements AuthStrategy
{
    /**
     * @param string $apiKey       The API key to send.
     * @param string $headerName   HTTP header name (default: "Authorization").
     * @param string $valuePrefix  Prefix before the key (default: "Bearer ").
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $headerName = 'Authorization',
        private readonly string $valuePrefix = 'Bearer ',
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array
    {
        $headers[$this->headerName] = $this->valuePrefix . $this->apiKey;

        return $headers;
    }
}

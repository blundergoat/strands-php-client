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
     * Prepares auth headers before the app request reaches the agent.
     *
     * @param array<string, string> $headers headers that will reach the agent service.
     *
     * @param string $method HTTP method used for signing and middleware context.
     * @param string $url agent endpoint the app is calling.
     * @param string $body request body the agent service will receive.
     * @return array<string, string> Headers with the configured API key attached.
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array
    {
        $headers[$this->headerName] = $this->valuePrefix . $this->apiKey;

        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Auth;

/**
 * No-op authentication strategy for local development.
 *
 * Returns headers unchanged. Used by default when no auth is configured.
 */
class NullAuth implements AuthStrategy
{
    /**
     * Prepares auth headers before the app request reaches the agent.
     *
     * @param array<string, string> $headers headers that will reach the agent service.
     *
     * @param string $method HTTP method used for signing and middleware context.
     * @param string $url agent endpoint the app is calling.
     * @param string $body request body the agent service will receive.
     * @return array<string, string> Headers left unchanged for unauthenticated calls.
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array
    {
        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace Strands\Auth;

/**
 * No-op authentication strategy for local development.
 *
 * Returns headers unchanged. Used by default when no auth is configured.
 */
class NullAuth implements AuthStrategy
{
    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array
    {
        return $headers;
    }
}

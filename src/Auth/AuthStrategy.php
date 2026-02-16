<?php

declare(strict_types=1);

namespace Strands\Auth;

/**
 * Strategy interface for applying authentication to outgoing requests.
 */
interface AuthStrategy
{
    /**
     * Apply authentication to the request headers.
     *
     * @param array<string, string> $headers  Existing HTTP headers.
     * @param string $method  HTTP method (e.g. 'POST').
     * @param string $url     Request URL.
     * @param string $body    Request body.
     *
     * @return array<string, string>  Headers with authentication applied.
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array;
}

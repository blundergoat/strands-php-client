<?php

declare(strict_types=1);

namespace Strands\Http;

/**
 * Interface for HTTP transport implementations.
 */
interface HttpTransport
{
    /**
     * Send a POST request and return the decoded response body.
     *
     * @param string               $url             The full URL to POST to.
     * @param array<string, string> $headers         HTTP headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Maximum seconds for the overall request.
     * @param int                  $connectTimeout  Maximum seconds to wait for the initial connection.
     *
     * @return array<string, mixed>  The decoded JSON response.
     */
    public function post(string $url, array $headers, string $body, int $timeout, int $connectTimeout): array;

    /**
     * Send a POST request and stream the SSE response in chunks.
     *
     * @param string               $url             The full URL to POST to.
     * @param array<string, string> $headers         HTTP headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Maximum seconds to wait between chunks.
     * @param int                  $connectTimeout  Maximum seconds to wait for the initial connection.
     * @param callable(string): void $onChunk       Called with each raw SSE data chunk.
     */
    public function stream(string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void;
}

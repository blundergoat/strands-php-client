<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

/**
 * The seam that carries a request to the agent and brings the answer back.
 *
 * StrandsClient uses it for invoke and stream calls while each implementation owns network behavior and errors.
 * Apps select Symfony for live SSE or a PSR-18 client for plain requests without changing client call sites.
 */
interface HttpTransport
{
    /**
     * Send one request and hand back the agent's answer — the backbone of invoke().
     *
     * @param string               $url             The full URL to POST to.
     * @param array<string, string> $headers         HTTP headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Maximum seconds for the overall request.
     * @param int                  $connectTimeout  Maximum seconds to wait for the initial connection.
     *
     * @return array<string, mixed>  The decoded JSON response; empty only if the agent returned no fields.
     */
    public function post(string $url, array $headers, string $body, int $timeout, int $connectTimeout): array;

    /**
     * Stream raw SSE chunks to the app so it can render the answer as it arrives.
     * Return false from the callback to stop generation; void, null, or any other value keeps the connection open.
     *
     * @param string               $url             The full URL to POST to.
     * @param array<string, string> $headers         HTTP headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Maximum seconds to wait between chunks.
     * @param int                  $connectTimeout  Maximum seconds to wait for the initial connection.
     * @param callable(string): (void|bool) $onChunk  Called with each raw SSE data chunk. Return false to cancel.
     * @return void No returned value; updates client or observer state.
     */
    public function stream(string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void;
}

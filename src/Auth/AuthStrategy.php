<?php

declare(strict_types=1);

namespace StrandsPhpClient\Auth;

/**
 * Contract for attaching credentials to every request before it leaves the app.
 *
 * Every invoke or stream call uses the selected API-key, SigV4, or no-auth strategy.
 * Use it to change gateway authentication without changing application call sites.
 */
interface AuthStrategy
{
    /**
     * Attach credentials to a request just before it is sent to the agent.
     *
     * The transport calls this for every invoke/stream request; the headers it
     * returns are exactly what travels over the wire.
     *
     * @param array<string, string> $headers  Headers assembled so far for the request.
     * @param string $method  HTTP method (e.g. 'POST'), used by signing strategies.
     * @param string $url      Full agent endpoint the request is going to.
     * @param string $body     Serialized request body, used when the signature covers it.
     *
     * @return array<string, string>  Headers with authentication applied, ready to send.
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array;
}

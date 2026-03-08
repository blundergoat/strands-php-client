<?php

/**
 * Configuration settings for connecting to a Strands agent.
 *
 * Holds the agent URL, authentication strategy, timeouts, and retry behaviour.
 */

declare(strict_types=1);

namespace StrandsPhpClient\Config;

use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Auth\NullAuth;

/**
 * Configuration for connecting to a Strands agent.
 */
class StrandsConfig
{
    /**
     * @param string       $endpoint       The full URL of the Strands agent API.
     * @param AuthStrategy $auth           Authentication strategy (default: NullAuth).
     * @param int          $timeout        Response timeout in seconds (default: 120).
     * @param int          $connectTimeout Connection timeout in seconds (default: 10).
     *                                     Separate from the read timeout so slow LLM
     *                                     generation doesn't get confused with a down server.
     * @param int          $maxRetries     Maximum number of retries on transient errors (default: 0).
     * @param int          $retryDelayMs   Base delay between retries in milliseconds (default: 500).
     *                                     Doubles on each subsequent retry (exponential backoff).
     * @param list<int>    $retryableStatusCodes  HTTP status codes that trigger a retry.
     *
     * @throws \InvalidArgumentException If any parameter value is out of range.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly AuthStrategy $auth = new NullAuth(),
        public readonly int $timeout = 120,
        public readonly int $connectTimeout = 10,
        public readonly int $maxRetries = 0,
        public readonly int $retryDelayMs = 500,
        public readonly array $retryableStatusCodes = [429, 502, 503, 504],
    ) {
        $parts = parse_url($endpoint);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid endpoint URL: "%s"', $endpoint));
        }

        if ($timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        if ($connectTimeout < 1) {
            throw new \InvalidArgumentException('connectTimeout must be at least 1');
        }

        if ($maxRetries < 0 || $maxRetries > 20) {
            throw new \InvalidArgumentException('maxRetries must be between 0 and 20');
        }

        if ($retryDelayMs < 1) {
            throw new \InvalidArgumentException('retryDelayMs must be at least 1');
        }

        // Only 4xx/5xx codes make sense for retry - retrying on 2xx/3xx
        // would mask successful responses as errors.
        foreach ($retryableStatusCodes as $code) {
            if ($code < 400 || $code > 599) {
                throw new \InvalidArgumentException(
                    sprintf('All retryableStatusCodes must be HTTP error codes (400-599), but got: %s', (string) $code),
                );
            }
        }
    }
}

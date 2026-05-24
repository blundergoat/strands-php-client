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
        self::assertValidEndpoint($endpoint);
        self::assertMinimum('timeout', $timeout, 1);
        self::assertMinimum('connectTimeout', $connectTimeout, 1);
        self::assertRange('maxRetries', $maxRetries, 0, 20);
        self::assertMinimum('retryDelayMs', $retryDelayMs, 1);
        self::assertRetryableStatusCodes($retryableStatusCodes);
    }

    /**
     * Validate that the endpoint is an absolute HTTP(S) URL.
     *
     * @param string $endpoint Endpoint URL supplied by the caller.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the endpoint is not an HTTP(S) URL.
     */
    private static function assertValidEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (is_array($parts) && isset($parts['host']) && in_array($scheme, ['http', 'https'], true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf('Invalid endpoint URL: "%s"', $endpoint));
    }

    /**
     * Validate that an integer option is at least the configured minimum.
     *
     * @param string $name  Option name used in exception messages.
     * @param int    $value Option value supplied by the caller.
     * @param int    $min   Inclusive minimum value.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the value is below the minimum.
     */
    private static function assertMinimum(string $name, int $value, int $min): void
    {
        if ($value >= $min) {
            return;
        }

        throw new \InvalidArgumentException(sprintf('%s must be at least %d', $name, $min));
    }

    /**
     * Validate that an integer option falls inside an inclusive range.
     *
     * @param string $name  Option name used in exception messages.
     * @param int    $value Option value supplied by the caller.
     * @param int    $min   Inclusive minimum value.
     * @param int    $max   Inclusive maximum value.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the value falls outside the range.
     */
    private static function assertRange(string $name, int $value, int $min, int $max): void
    {
        if ($value >= $min && $value <= $max) {
            return;
        }

        throw new \InvalidArgumentException(sprintf('%s must be between %d and %d', $name, $min, $max));
    }

    /**
     * Validate retryable HTTP status codes.
     *
     * Only 4xx/5xx codes make sense for retry; retrying on 2xx/3xx would mask
     * successful responses as errors.
     *
     * @param list<int> $retryableStatusCodes HTTP status codes that trigger a retry.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If any code is outside the 400-599 range.
     */
    private static function assertRetryableStatusCodes(array $retryableStatusCodes): void
    {
        foreach ($retryableStatusCodes as $statusCode) {
            if ($statusCode >= 400 && $statusCode <= 599) {
                continue;
            }

            throw new \InvalidArgumentException(
                sprintf('All retryableStatusCodes must be HTTP error codes (400-599), but got: %s', (string) $statusCode),
            );
        }
    }
}

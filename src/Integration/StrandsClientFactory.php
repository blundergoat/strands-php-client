<?php

/**
 * Factory for creating StrandsClient instances from agent configuration arrays.
 *
 * Shared base class used by both the Symfony bundle and Laravel service provider.
 */

declare(strict_types=1);

namespace StrandsPhpClient\Integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Auth\ApiKeyAuth;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Auth\NullAuth;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\StrandsClient;

class StrandsClientFactory
{
    /** @var list<RequestMiddleware> */
    private readonly array $middleware;

    /**
     * @param array<string, array{
     *     endpoint: string,
     *     auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string},
     *     timeout: int,
     *     connect_timeout?: int,
     *     max_retries?: int,
     *     retry_delay_ms?: int,
     *     retryable_status_codes?: list<int>,
     * }> $agents
     * @param iterable<RequestMiddleware> $middleware
     */
    public function __construct(
        private readonly array $agents,
        private readonly LoggerInterface $logger = new NullLogger(),
        iterable $middleware = [],
    ) {
        // Normalise to a plain list so we can pass it to StrandsClient.
        // Symfony DI passes a tagged iterator (Traversable), Laravel passes an array.
        $this->middleware = array_values(
            $middleware instanceof \Traversable
                ? iterator_to_array($middleware, false)
                : $middleware,
        );
    }

    /**
     * Create a StrandsClient for the given agent name.
     *
     * @throws \InvalidArgumentException  If the agent name doesn't exist in the configuration.
     */
    public function create(string $agentName): StrandsClient
    {
        if (!isset($this->agents[$agentName])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Strands agent "%s". Configured agents: %s',
                $agentName,
                implode(', ', array_keys($this->agents)),
            ));
        }

        $config = $this->agents[$agentName];

        // All agents created by this factory share the same middleware stack.
        // Per-agent middleware is not supported — use separate factories if needed.
        return new StrandsClient(
            config: new StrandsConfig(
                endpoint: $config['endpoint'],
                auth: $this->resolveAuth($config['auth']),
                timeout: $config['timeout'],
                connectTimeout: $config['connect_timeout'] ?? 10,
                maxRetries: $config['max_retries'] ?? 0,
                retryDelayMs: $config['retry_delay_ms'] ?? 500,
                retryableStatusCodes: $config['retryable_status_codes'] ?? [429, 502, 503, 504],
            ),
            logger: $this->logger,
            middleware: $this->middleware,
        );
    }

    /**
     * @param array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string} $authConfig
     */
    private function resolveAuth(array $authConfig): AuthStrategy
    {
        return match ($authConfig['driver']) {
            'null' => new NullAuth(),
            'api_key' => $this->createApiKeyAuth($authConfig),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported auth driver "%s". Supported: null, api_key',
                $authConfig['driver'],
            )),
        };
    }

    /**
     * @param array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string} $authConfig
     */
    private function createApiKeyAuth(array $authConfig): ApiKeyAuth
    {
        $apiKey = $authConfig['api_key'] ?? null;

        if ($apiKey === null || $apiKey === '') {
            throw new \InvalidArgumentException(
                'The "api_key" option is required when using the "api_key" auth driver.',
            );
        }

        return new ApiKeyAuth(
            apiKey: $apiKey,
            headerName: $authConfig['header_name'] ?? 'Authorization',
            valuePrefix: $authConfig['value_prefix'] ?? 'Bearer ',
        );
    }
}

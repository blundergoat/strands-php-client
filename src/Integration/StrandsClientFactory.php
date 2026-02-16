<?php

/**
 * Factory for creating StrandsClient instances from agent configuration arrays.
 *
 * Shared base class used by both the Symfony bundle and Laravel service provider.
 */

declare(strict_types=1);

namespace Strands\Integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Strands\Auth\ApiKeyAuth;
use Strands\Auth\AuthStrategy;
use Strands\Auth\NullAuth;
use Strands\Config\StrandsConfig;
use Strands\StrandsClient;

class StrandsClientFactory
{
    /**
     * @param array<string, array{
     *     endpoint: string,
     *     auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string},
     *     timeout: int,
     *     connect_timeout?: int,
     *     max_retries?: int,
     *     retry_delay_ms?: int,
     * }> $agents
     */
    public function __construct(
        private readonly array $agents,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
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

        return new StrandsClient(
            config: new StrandsConfig(
                endpoint: $config['endpoint'],
                auth: $this->resolveAuth($config['auth']),
                timeout: $config['timeout'],
                connectTimeout: $config['connect_timeout'] ?? 10,
                maxRetries: $config['max_retries'] ?? 0,
                retryDelayMs: $config['retry_delay_ms'] ?? 500,
            ),
            logger: $this->logger,
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

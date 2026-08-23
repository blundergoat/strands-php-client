<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Auth\ApiKeyAuth;
use StrandsPhpClient\Auth\AuthStrategy;
use StrandsPhpClient\Auth\NullAuth;
use StrandsPhpClient\Auth\SigV4Auth;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\StrandsClient;

/**
 * Factory for creating StrandsClient instances from agent configuration arrays.
 *
 * Laravel and Symfony share it to turn each named agent into a separately configured client.
 * Use create() when app code needs the endpoint, authentication, timeouts, and retries for one agent.
 */
class StrandsClientFactory
{
    /** @var list<RequestMiddleware> */
    private readonly array $middleware;

    /** @var list<ResponseObserver> */
    private readonly array $responseObservers;

    /**
     * Hold the app's agent configs plus shared middleware and observers.
     *
     * The framework integration builds this once from config; create() then
     * makes a ready client per named agent on demand.
     *
     * @param array<string, array{
     *     endpoint: string,
     *     auth: array{driver: string, api_key?: string|null, header_name?: string, value_prefix?: string},
     *     timeout: int,
     *     connect_timeout?: int,
     *     max_retries?: int,
     *     retry_delay_ms?: int,
     *     retryable_status_codes?: list<int>,
     * }> $agents
     * @param iterable<RequestMiddleware> $middleware hooks applied around each agent call.
     * @param iterable<ResponseObserver> $responseObservers observers that receive parsed agent results.
     * @param LoggerInterface $logger logger shared by clients created for app agents.
     */
    public function __construct(
        private readonly array $agents,
        private readonly LoggerInterface $logger = new NullLogger(),
        iterable $middleware = [],
        iterable $responseObservers = [],
    ) {
        // Normalise to a plain list so we can pass it to StrandsClient.
        // Symfony DI passes a tagged iterator (Traversable), Laravel passes an array.
        $this->middleware = array_values(
            $middleware instanceof \Traversable
                ? iterator_to_array($middleware, false)
                : $middleware,
        );
        $this->responseObservers = array_values(
            $responseObservers instanceof \Traversable
                ? iterator_to_array($responseObservers, false)
                : $responseObservers,
        );
    }

    /**
     * Create a StrandsClient for the given agent name.
     *
     * @param string $agentName Which configured agent to build a client for (e.g. "support").
     * @return StrandsClient A ready client wired to that agent's endpoint, auth, and timeouts.
     * @throws \InvalidArgumentException  If the agent name doesn't exist in the configuration.
     */
    public function create(string $agentName): StrandsClient
    {
        // The caller selects one configured agent name, such as "support" or "analyst".
        if (!isset($this->agents[$agentName])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Strands agent "%s". Configured agents: %s',
                $agentName,
                implode(', ', array_keys($this->agents)),
            ));
        }

        $agentConfig = $this->agents[$agentName];

        // All agents created by this factory share the same middleware stack.
        // Per-agent middleware is not supported - use separate factories if needed.
        return new StrandsClient(
            config: new StrandsConfig(
                endpoint: $agentConfig['endpoint'],
                auth: $this->resolveAuth($agentConfig['auth']),
                timeout: $agentConfig['timeout'],
                connectTimeout: $agentConfig['connect_timeout'] ?? 10,
                maxRetries: $agentConfig['max_retries'] ?? 0,
                retryDelayMs: $agentConfig['retry_delay_ms'] ?? 500,
                retryableStatusCodes: $agentConfig['retryable_status_codes'] ?? [429, 502, 503, 504],
            ),
            logger: $this->logger,
            middleware: $this->middleware,
            responseObservers: $this->responseObservers,
        );
    }

    /**
     * Turn the agent's `auth` config block into the matching auth strategy.
     *
     * @param array{
     *     driver: string,
     *     api_key?: string|null,
     *     header_name?: string,
     *     value_prefix?: string,
     *     region?: string,
     *     service?: string,
     *     access_key_id?: string|null,
     *     secret_access_key?: string|null,
     *     session_token?: string|null
     * } $authConfig Framework auth settings; an empty map is invalid because every agent needs a driver.
     * @return AuthStrategy Strategy the client uses to sign every request (NullAuth, ApiKeyAuth, or SigV4Auth).
     */
    private function resolveAuth(array $authConfig): AuthStrategy
    {
        return match ($authConfig['driver']) {
            'null' => new NullAuth(),
            'api_key' => $this->createApiKeyAuth($authConfig),
            'sigv4' => $this->createSigV4Auth($authConfig),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported auth driver "%s". Supported: null, api_key, sigv4',
                $authConfig['driver'],
            )),
        };
    }

    /**
     * Build API-key auth from the agent config (the common hosted-endpoint case).
     *
     * @param array{
     *     driver: string,
     *     api_key?: string|null,
     *     header_name?: string,
     *     value_prefix?: string
     * } $authConfig API-key settings; an empty map cannot identify the driver or required key.
     * @return ApiKeyAuth Strategy that attaches the configured API key to every request.
     */
    private function createApiKeyAuth(array $authConfig): ApiKeyAuth
    {
        $apiKey = $authConfig['api_key'] ?? null;

        // The app chose the api_key driver but left the key blank — fail loudly at boot.
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

    /**
     * Build AWS SigV4 auth from the agent config (for IAM-protected endpoints).
     *
     * @param array{
     *     driver: string,
     *     region?: string,
     *     service?: string,
     *     access_key_id?: string|null,
     *     secret_access_key?: string|null,
     *     session_token?: string|null
     * } $authConfig SigV4 settings; an empty map cannot identify the driver or required region.
     * @return SigV4Auth Strategy that signs every request with an AWS SigV4 signature.
     */
    private function createSigV4Auth(array $authConfig): SigV4Auth
    {
        $region = $authConfig['region'] ?? null;

        // SigV4 signatures are scoped to a region, so a blank region can't work.
        if ($region === null || $region === '') {
            throw new \InvalidArgumentException(
                'The "region" option is required when using the "sigv4" auth driver.',
            );
        }

        $service = $authConfig['service'] ?? 'execute-api';

        // If explicit credentials provided, use them. Otherwise try environment.
        $accessKeyId = $authConfig['access_key_id'] ?? null;
        $secretAccessKey = $authConfig['secret_access_key'] ?? null;

        $hasAccessKey = $accessKeyId !== null && $accessKeyId !== '';
        $hasSecretKey = $secretAccessKey !== null && $secretAccessKey !== '';

        // Reject asymmetric credentials - providing only one is almost certainly
        // a configuration error and would silently fall through to env vars.
        if ($hasAccessKey !== $hasSecretKey) {
            throw new \InvalidArgumentException(
                'Both "access_key_id" and "secret_access_key" must be provided together for the "sigv4" auth driver. '
                . 'To use environment variables, omit both.',
            );
        }

        // Both keys were supplied inline (e.g. from a secrets manager) — use them directly.
        if ($hasAccessKey && $hasSecretKey) {
            /** @var string $accessKeyId validated before app code uses it. */
            /** @var string $secretAccessKey validated before app code uses it. */
            return new SigV4Auth(
                accessKeyId: $accessKeyId,
                secretAccessKey: $secretAccessKey,
                region: $region,
                service: $service,
                sessionToken: $authConfig['session_token'] ?? null,
            );
        }

        return SigV4Auth::fromEnvironment($region, $service);
    }
}

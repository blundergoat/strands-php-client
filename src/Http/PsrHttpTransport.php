<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;

/**
 * PSR-18 based transport for framework-agnostic HTTP calls.
 *
 * Use it with a PSR-18 client for invoke() calls in apps that do not need live SSE updates.
 * Configure timeouts on the underlying client; choose SymfonyHttpTransport when the caller needs streaming.
 */
class PsrHttpTransport implements HttpTransport
{
    /** Logger for transport warnings that do not change request outcomes. */
    private LoggerInterface $logger;

    /** Tracks whether the one-time timeout warning has already been shown. */
    private bool $hasLoggedTimeoutWarning = false;

    /**
     * Wire up a PSR-18 transport from the app's own HTTP client and factories.
     *
     * Use this for invoke-only setups where streaming isn't needed; pass the
     * resulting transport to the StrandsClient constructor.
     *
     * @param ClientInterface         $httpClient      PSR-18 HTTP client.
     * @param RequestFactoryInterface $requestFactory  PSR-7 request factory.
     * @param StreamFactoryInterface  $streamFactory   PSR-7 stream factory.
     * @param LoggerInterface|null    $logger          PSR-3 logger (NullLogger if null).
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sends a synchronous agent request through a PSR-18 client.
     *
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Ignored - configure on your PSR-18 client.
     * @param int                  $connectTimeout  Ignored - configure on your PSR-18 client.
     *
     * @return array<string, mixed> Decoded agent response returned to the client.
     *
     * @throws AgentErrorException  If the server returned an error (HTTP 400+).
     * @throws StrandsException     If the JSON response is invalid or the request failed.
     */
    public function post(string $url, array $headers, string $body, int $timeout, int $connectTimeout): array
    {
        // PSR-18 clients own their own timeouts, so warn once that our values are ignored.
        if (!$this->hasLoggedTimeoutWarning) {
            $this->hasLoggedTimeoutWarning = true;
            $this->logger->notice(
                'PsrHttpTransport does not support timeout parameters. '
                . 'Configure timeout on your PSR-18 client instance directly.',
                ['timeout' => $timeout, 'connectTimeout' => $connectTimeout],
            );
        }

        try {
            $request = $this->requestFactory->createRequest('POST', $url);

            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $request = $request->withBody(
                $this->streamFactory->createStream($body),
            );

            $response = $this->httpClient->sendRequest($request);

            $statusCode = $response->getStatusCode();
            $content = (string) $response->getBody();
            $responseData = json_decode($content, true);

            // Any 4xx/5xx means the agent rejected the request — surface it as a typed error.
            if ($statusCode >= 400) {
                throw AgentErrorException::fromHttpResponse($statusCode, $content, $responseData);
            }

            // A 2xx that isn't a JSON object means a broken/proxy response, not a real answer.
            if (!is_array($responseData)) {
                throw new StrandsException(sprintf(
                    'Expected JSON object from %s, got %s',
                    $url,
                    get_debug_type($responseData),
                ));
            }

            /** @var array<string, mixed> $responseData Validated agent response shown to app code. */
            return $responseData;
        } catch (StrandsException $strandsException) {
            // For example, an agent can return a documented 429 or invalid JSON response; preserve that caller-ready exception unchanged.
            throw $strandsException;
        } catch (\Throwable $transportException) {
            // For example, DNS, TLS, or the app's PSR-18 client can fail before an agent response exists; wrap that transport failure consistently.
            throw new StrandsException(
                'HTTP request to agent failed: ' . $transportException->getMessage(),
                previous: $transportException,
            );
        }
    }

    /**
     * Streaming is not supported by PSR-18.
     *
     * @param string $url agent endpoint the app is calling.
     * @param array<string, string> $headers headers that will reach the agent service.
     * @param string $body request body the agent service will receive.
     * @param int $timeout Request timeout used for the agent call.
     * @param int $connectTimeout Connection timeout used for the agent request.
     * @param callable $onChunk Callback that receives raw stream chunks.
     * @return void No returned value; updates client or observer state.
     * @throws StrandsException Always thrown.
     */
    public function stream(string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void
    {
        throw new StrandsException(
            'SSE streaming is not supported by PsrHttpTransport. '
            . 'Install symfony/http-client and use SymfonyHttpTransport for streaming support.',
        );
    }
}

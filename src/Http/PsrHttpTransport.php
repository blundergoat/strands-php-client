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
 * Supports invoke() via any PSR-18 client (Guzzle, Buzz, etc.).
 * SSE streaming is not supported - PSR-18 has no chunked transfer API.
 * Timeout must be configured on the underlying client instance.
 */
class PsrHttpTransport implements HttpTransport
{
    private LoggerInterface $logger;

    private bool $timeoutWarningLogged = false;

    /**
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
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Ignored - configure on your PSR-18 client.
     * @param int                  $connectTimeout  Ignored - configure on your PSR-18 client.
     *
     * @return array<string, mixed>
     *
     * @throws AgentErrorException  If the server returned an error (HTTP 400+).
     * @throws StrandsException     If the JSON response is invalid or the request failed.
     */
    public function post(string $url, array $headers, string $body, int $timeout, int $connectTimeout): array
    {
        if (!$this->timeoutWarningLogged) {
            $this->timeoutWarningLogged = true;
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
            $data = json_decode($content, true);

            if ($statusCode >= 400) {
                throw AgentErrorException::fromHttpResponse($statusCode, $content, $data);
            }

            if (!is_array($data)) {
                throw new StrandsException('Invalid JSON response from agent');
            }

            /** @var array<string, mixed> $data */
            return $data;
        } catch (StrandsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StrandsException(
                'HTTP request to agent failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Streaming is not supported by PSR-18.
     *
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

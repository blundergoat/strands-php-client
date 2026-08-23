<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Exceptions\StreamInterruptedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP transport using Symfony's HTTP client.
 *
 * Supports both synchronous invoke() and real-time SSE stream() calls.
 * Auto-detected when symfony/http-client is installed.
 */
class SymfonyHttpTransport implements HttpTransport
{
    /** HTTP client used for invoke and live stream requests. */
    private HttpClientInterface $httpClient;

    /**
     * Create a Symfony transport, using the default HTTP client when none is supplied.
     *
     * @param HttpClientInterface|null $httpClient Client supplied by the app; null creates Symfony's default client.
     */
    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * Sends a synchronous agent request through Symfony HTTP Client.
     *
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Overall request timeout in seconds.
     * @param int                  $connectTimeout  Connection/idle timeout in seconds.
     *
     * @return array<string, mixed> Decoded agent response returned to the client.
     *
     * @throws AgentErrorException  If the server returned an error (HTTP 400+).
     * @throws StrandsException     If the JSON is invalid or the request failed.
     */
    public function post(string $url, array $headers, string $body, int $timeout, int $connectTimeout): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $connectTimeout,
                'max_duration' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
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
            // For example, DNS, TLS, or Symfony's client can fail before an agent response exists; wrap that transport failure consistently.
            throw new StrandsException(
                'HTTP request to agent failed: ' . $transportException->getMessage(),
                previous: $transportException,
            );
        }
    }

    /**
     * Open a live SSE connection and push each chunk to the app as it arrives.
     *
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Per-chunk idle timeout in seconds.
     * @param int                  $connectTimeout  Connection timeout in seconds.
     * @param callable(string): (void|bool) $onChunk  Called with each raw SSE data chunk. Return false to cancel.
     *
     * @return void No returned value; updates client or observer state.
     * @throws AgentErrorException          If the server returned an error.
     * @throws StreamInterruptedException   If the stream times out.
     * @throws StrandsException             If the request failed.
     */
    public function stream(string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk): void
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $connectTimeout,
            ]);

            $statusCode = $response->getStatusCode();
            // Reject an error status before opening the stream callback lifecycle.
            if ($statusCode >= 400) {
                $content = $response->getContent(false);
                throw AgentErrorException::fromHttpResponse($statusCode, $content, json_decode($content, true));
            }

            // Pull chunks as the agent produces them so callbacks receive incremental output.
            foreach ($this->httpClient->stream($response, $timeout) as $chunk) {
                // A gap longer than the idle timeout means the stream stalled; stop waiting.
                if ($chunk->isTimeout()) {
                    throw new StreamInterruptedException('Stream timed out');
                }

                $content = $chunk->getContent();

                // Forward only non-empty chunks (Symfony also emits empty control chunks).
                if ($content !== '') {
                    // The callback can return false to cancel before the terminal chunk.
                    if ($onChunk($content) === false) {
                        $response->cancel();

                        break;
                    }
                }

                // The final chunk marks a clean transport-level end.
                if ($chunk->isLast()) {
                    break;
                }
            }
        } catch (StrandsException $strandsException) {
            // Agent errors and idle timeouts already carry caller-ready details; preserve them unchanged.
            throw $strandsException;
        } catch (\Throwable $transportException) {
            // For example, Symfony can lose the socket while an answer is streaming; wrap it as the client's standard transport failure.
            throw new StrandsException(
                'Streaming request to agent failed: ' . $transportException->getMessage(),
                previous: $transportException,
            );
        }
    }
}

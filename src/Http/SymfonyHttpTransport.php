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
     * @param HttpClientInterface|null $httpClient Optional Symfony HTTP client instance.
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
            $data = json_decode($content, true);

            // Any 4xx/5xx means the agent rejected the request — surface it as a typed error.
            if ($statusCode >= 400) {
                throw AgentErrorException::fromHttpResponse($statusCode, $content, $data);
            }

            // A 2xx that isn't a JSON object means a broken/proxy response, not a real answer.
            if (!is_array($data)) {
                throw new StrandsException(sprintf(
                    'Expected JSON object from %s, got %s',
                    $url,
                    get_debug_type($data),
                ));
            }

            /** @var array<string, mixed> $data validated before app code uses it. */
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
            // Reject up front on an error status so the user sees a clear failure, not a dead stream.
            if ($statusCode >= 400) {
                $content = $response->getContent(false);
                throw AgentErrorException::fromHttpResponse($statusCode, $content, json_decode($content, true));
            }

            // Pull chunks as the agent produces them — this is what makes the answer appear live.
            foreach ($this->httpClient->stream($response, $timeout) as $chunk) {
                // A gap longer than the idle timeout means the stream stalled; stop waiting.
                if ($chunk->isTimeout()) {
                    throw new StreamInterruptedException('Stream timed out');
                }

                $content = $chunk->getContent();

                // Forward only non-empty chunks (Symfony also emits empty control chunks).
                if ($content !== '') {
                    // The app returns false to stop early (e.g. the user hit "stop generating").
                    if ($onChunk($content) === false) {
                        $response->cancel();

                        break;
                    }
                }

                // The final chunk marks a clean end of the answer.
                if ($chunk->isLast()) {
                    break;
                }
            }
        } catch (StrandsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StrandsException(
                'Streaming request to agent failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}

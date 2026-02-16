<?php

declare(strict_types=1);

namespace Strands\Http;

use Strands\Exceptions\AgentErrorException;
use Strands\Exceptions\StrandsException;
use Strands\Exceptions\StreamInterruptedException;
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
    private HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Overall request timeout in seconds.
     * @param int                  $connectTimeout  Connection/idle timeout in seconds.
     *
     * @return array<string, mixed>
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

            if ($statusCode >= 400) {
                $errorData = is_array($data) ? $data : [];
                $detail = $errorData['detail'] ?? $errorData['error'] ?? $content;
                $errorMessage = is_string($detail) ? $detail : (json_encode($detail) ?: 'Unknown agent error');

                throw new AgentErrorException(
                    message: $errorMessage,
                    statusCode: $statusCode,
                );
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
     * @param string               $url             The URL to POST to.
     * @param array<string, string> $headers         Headers to include.
     * @param string               $body            JSON-encoded request body.
     * @param int                  $timeout         Per-chunk idle timeout in seconds.
     * @param int                  $connectTimeout  Connection timeout in seconds.
     * @param callable(string): void $onChunk       Called with each raw SSE data chunk.
     *
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
            if ($statusCode >= 400) {
                $content = $response->getContent(false);
                $data = json_decode($content, true);
                $errorData = is_array($data) ? $data : [];
                $detail = $errorData['detail'] ?? $errorData['error'] ?? $content;
                $errorMessage = is_string($detail) ? $detail : (json_encode($detail) ?: 'Unknown agent error');

                throw new AgentErrorException(
                    message: $errorMessage,
                    statusCode: $statusCode,
                );
            }

            foreach ($this->httpClient->stream($response, $timeout) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new StreamInterruptedException('Stream timed out');
                }

                $content = $chunk->getContent();

                if ($content !== '') {
                    $onChunk($content);
                }

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

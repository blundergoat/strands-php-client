<?php

declare(strict_types=1);

namespace StrandsPhpClient;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\SymfonyHttpTransport;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Usage;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;
use StrandsPhpClient\Streaming\StreamResult;

/**
 * The primary client for interacting with Strands AI agents.
 */
class StrandsClient
{
    private HttpTransport $transport;

    private LoggerInterface $logger;

    /** @var list<RequestMiddleware> */
    private readonly array $middleware;

    /**
     * @param StrandsConfig              $config      Agent endpoint, auth, timeouts, retry settings.
     * @param HttpTransport|null         $transport   HTTP transport (auto-detected if null).
     * @param LoggerInterface|null       $logger      PSR-3 logger (NullLogger if null).
     * @param list<RequestMiddleware>    $middleware   Request middleware (executed in order).
     */
    public function __construct(
        private readonly StrandsConfig $config,
        ?HttpTransport $transport = null,
        ?LoggerInterface $logger = null,
        array $middleware = [],
    ) {
        $this->transport = $transport ?? self::detectTransport();
        $this->logger = $logger ?? new NullLogger();
        $this->middleware = $middleware;
    }

    /**
     * Send a message and wait for the complete response.
     *
     * @param string            $message         The message to send to the agent.
     * @param AgentContext|null  $context         Optional extra context (system prompts, metadata).
     * @param string|null        $sessionId       Optional session ID to continue a conversation.
     * @param int|null           $timeoutSeconds  Override the config timeout for this request only.
     *
     * @return AgentResponse
     *
     * @throws \InvalidArgumentException  If timeoutSeconds is less than 1.
     */
    public function invoke(
        string $message,
        ?AgentContext $context = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
    ): AgentResponse {
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }

        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/invoke';
        [$headers, $body] = $this->buildRequest($url, $message, $context, $sessionId, 'application/json');

        $this->logger->debug('Strands invoke request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        $startTime = hrtime(true);

        try {
            $data = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;
        $this->notifyAfterResponse($url, 200, $durationMs);

        $response = AgentResponse::fromArray($data);

        $this->logger->debug('Strands invoke response', [
            'session_id' => $response->sessionId,
            'agent' => $response->agent,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'tools_used' => count($response->toolsUsed),
        ]);

        return $response;
    }

    /**
     * Send a message and receive the response as a real-time stream of events.
     *
     * @param string            $message         The message to send to the agent.
     * @param callable(StreamEvent): (void|bool) $onEvent  Called for each event as it arrives. Return false to cancel.
     * @param AgentContext|null  $context         Optional extra context.
     * @param string|null        $sessionId       Optional session ID.
     * @param int|null           $timeoutSeconds  Override the config timeout for this request only.
     *
     * @return StreamResult
     *
     * @throws Exceptions\StreamInterruptedException  If the stream ends without a terminal event.
     * @throws \InvalidArgumentException              If timeoutSeconds is less than 1.
     */
    public function stream(
        string $message,
        callable $onEvent,
        ?AgentContext $context = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
    ): StreamResult {
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }

        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/stream';
        [$headers, $body] = $this->buildRequest($url, $message, $context, $sessionId, 'text/event-stream');

        $this->logger->debug('Strands stream request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        $parser = new StreamParser();
        $receivedTerminal = false;

        // We accumulate text from individual "text" events so callers get the
        // full response even if the "complete" event's fullText is missing.
        // completeFullText is the fallback sent by the server in the terminal event.
        $accumulatedText = '';
        $textEvents = 0;
        $totalEvents = 0;
        /** @var string|null $resultSessionId */
        $resultSessionId = null;
        /** @var array<string, mixed> $resultUsage */
        $resultUsage = [];
        /** @var list<array{name: string, duration_ms?: int}> $resultToolsUsed */
        $resultToolsUsed = [];
        /** @var string|null $completeFullText */
        $completeFullText = null;
        /** @var string|null $resultStopReason */
        $resultStopReason = null;

        $cancelled = false;

        $startTime = hrtime(true);

        try {
            $this->transport->stream($url, $headers, $body, $timeout, $this->config->connectTimeout, function (string $chunk) use ($parser, $onEvent, &$receivedTerminal, &$cancelled, &$accumulatedText, &$textEvents, &$totalEvents, &$resultSessionId, &$resultUsage, &$resultToolsUsed, &$completeFullText, &$resultStopReason): bool {
                if ($cancelled) {
                    return false;
                }

                $events = $parser->feed($chunk);

                foreach ($events as $event) {
                    $totalEvents++;

                    if ($event->type === StreamEventType::Text && $event->text !== null) {
                        $accumulatedText .= $event->text;
                        $textEvents++;
                    }

                    if ($event->isTerminal()) {
                        $receivedTerminal = true;

                        if ($event->type === StreamEventType::Complete) {
                            $resultSessionId = $event->sessionId;
                            $resultUsage = $event->usage;
                            $resultToolsUsed = $event->toolsUsed;
                            $completeFullText = $event->fullText;
                            $resultStopReason = $event->stopReason;
                        }
                    }

                    if ($onEvent($event) === false) {
                        $cancelled = true;

                        return false;
                    }
                }

                return true;
            });
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;

        // A stream that ends without a terminal event (complete/error) likely had
        // its TCP connection dropped. This is distinct from user cancellation.
        if (!$receivedTerminal && !$cancelled) {
            $interrupted = new Exceptions\StreamInterruptedException(
                'Stream ended without a terminal event (complete or error). The connection may have dropped.',
            );
            $this->notifyAfterResponse($url, 0, $durationMs, $interrupted);

            throw $interrupted;
        }

        // Report status 0 for cancelled streams because no complete HTTP
        // response was received — the user chose to stop early.
        $this->notifyAfterResponse($url, $cancelled ? 0 : 200, $durationMs);

        $finalText = $accumulatedText !== '' ? $accumulatedText : ($completeFullText ?? '');

        $stopReason = is_string($resultStopReason) ? Response\StopReason::tryFrom($resultStopReason) : null;

        $result = new StreamResult(
            text: $finalText,
            sessionId: $resultSessionId,
            usage: Usage::fromArray($resultUsage),
            toolsUsed: $resultToolsUsed,
            textEvents: $textEvents,
            totalEvents: $totalEvents,
            stopReason: $stopReason,
            cancelled: $cancelled,
        );

        $this->logger->debug('Strands stream complete', [
            'session_id' => $result->sessionId,
            'text_events' => $result->textEvents,
            'total_events' => $result->totalEvents,
            'text_length' => strlen($result->text),
            'input_tokens' => $result->usage->inputTokens,
            'output_tokens' => $result->usage->outputTokens,
        ]);

        return $result;
    }

    /**
     * Send a JSON POST to a custom endpoint path.
     *
     * Unlike invoke(), this accepts an arbitrary path and payload — useful for
     * agent endpoints with custom request/response schemas (file processing,
     * metadata extraction, etc.). Returns the raw decoded JSON array.
     *
     * @param string               $path     The endpoint path (e.g. '/file-summarise').
     * @param array<string, mixed> $payload  The JSON payload to send.
     * @param int|null             $timeout  Per-request timeout in seconds (null = use config default).
     *
     * @return array<string, mixed>  The decoded JSON response.
     *
     * @throws StrandsException           If the request fails or the payload cannot be encoded.
     * @throws \InvalidArgumentException  If timeout is less than 1.
     */
    public function postJson(string $path, array $payload, ?int $timeout = null): array
    {
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        $url = $this->buildUrl($path);
        [$headers, $body] = $this->buildJsonRequest($url, $payload, 'application/json');

        $this->logger->debug('Strands postJson request', [
            'url' => $url,
            'path' => $path,
        ]);

        $startTime = hrtime(true);

        try {
            $data = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;
        $this->notifyAfterResponse($url, 200, $durationMs);

        $this->logger->debug('Strands postJson response', [
            'url' => $url,
        ]);

        return $data;
    }

    /**
     * Stream SSE events from a custom endpoint path.
     *
     * Unlike stream(), this accepts an arbitrary path and payload, and delivers
     * raw decoded JSON arrays to the callback — preserving all fields including
     * domain-specific data that StreamEvent would discard.
     *
     * @param string               $path      The endpoint path (e.g. '/file-summarise-stream').
     * @param array<string, mixed> $payload   The JSON payload to send.
     * @param callable(array<string, mixed>): (void|bool) $onEvent  Called for each decoded SSE event. Return false to cancel.
     * @param int|null             $timeout   Per-request timeout in seconds (null = use config default).
     *
     * @throws StrandsException           If the request fails or the payload cannot be encoded.
     * @throws \InvalidArgumentException  If timeout is less than 1.
     */
    public function streamSse(string $path, array $payload, callable $onEvent, ?int $timeout = null): void
    {
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        $url = $this->buildUrl($path);
        [$headers, $body] = $this->buildJsonRequest($url, $payload, 'text/event-stream');

        $this->logger->debug('Strands streamSse request', [
            'url' => $url,
            'path' => $path,
        ]);

        $effectiveTimeout = $timeout ?? $this->config->timeout;
        $buffer = '';
        $cancelled = false;

        $startTime = hrtime(true);

        try {
            $this->transport->stream($url, $headers, $body, $effectiveTimeout, $this->config->connectTimeout, function (string $chunk) use (&$buffer, &$cancelled, $onEvent): bool {
                if ($cancelled) {
                    return false;
                }

                $buffer = str_replace(["\r\n", "\r"], "\n", $buffer . $chunk);

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $rawEvent = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $decoded = self::extractSseData($rawEvent);

                    if ($decoded !== null) {
                        if ($onEvent($decoded) === false) {
                            $cancelled = true;

                            return false;
                        }
                    }
                }

                return true;
            });
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        // Status 0 for cancelled streams (user returned false from onEvent),
        // 200 for streams that ran to natural completion.
        $durationMs = (hrtime(true) - $startTime) / 1e6;
        $this->notifyAfterResponse($url, $cancelled ? 0 : 200, $durationMs);

        $this->logger->debug('Strands streamSse complete', [
            'url' => $url,
        ]);
    }

    /**
     * Send a POST request with exponential-backoff retry on transient errors.
     *
     * @param string $url
     * @param array<string, string> $headers
     * @param string $body
     * @param int|null $timeout  Per-request timeout override (null = use config default).
     *
     * @return array<string, mixed>
     */
    private function postWithRetry(string $url, array $headers, string $body, ?int $timeout = null): array
    {
        $attempt = 0;
        $maxRetries = $this->config->maxRetries;
        $effectiveTimeout = $timeout ?? $this->config->timeout;

        while (true) {
            try {
                return $this->transport->post($url, $headers, $body, $effectiveTimeout, $this->config->connectTimeout);
            } catch (StrandsException $e) {
                // Only retry on status codes explicitly marked as retryable (e.g. 429, 502).
                // Non-retryable errors like 400 or 401 are thrown immediately.
                if (
                    $e instanceof AgentErrorException
                    && !in_array($e->statusCode, $this->config->retryableStatusCodes, true)
                ) {
                    throw $e;
                }

                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                // Exponential backoff with jitter (50-100% of base delay)
                // to avoid thundering herd when multiple clients retry simultaneously.
                // Capped at 30 seconds to prevent absurd delays at high retry counts.
                $baseDelay = min(
                    $this->config->retryDelayMs * (2 ** $attempt),
                    30_000,
                );
                $delayMs = (int) ($baseDelay * (0.5 + lcg_value() * 0.5));
                $attempt++;

                $this->logger->warning('Strands request failed, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);

                usleep($delayMs * 1000);
            }
        }
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     *
     * @throws StrandsException  If the payload cannot be JSON-encoded.
     */
    private function buildRequest(
        string $url,
        string $message,
        ?AgentContext $context,
        ?string $sessionId,
        string $accept,
    ): array {
        $payload = ['message' => $message];

        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }

        if ($context !== null) {
            $payload['context'] = $context->toArray();
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StrandsException(
                'Failed to encode request payload: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        // Middleware runs once per operation, not per retry attempt.
        // The modified headers/body are reused for all retry attempts.
        foreach ($this->middleware as $mw) {
            $result = $mw->beforeRequest($url, $headers, $body);
            $headers = $result['headers'];
            $body = $result['body'];
        }

        return [$headers, $body];
    }

    /**
     * Build the full URL for a custom endpoint path.
     */
    private function buildUrl(string $path): string
    {
        $base = rtrim($this->config->endpoint, '/');
        $path = ltrim($path, '/');

        if ($path === '') {
            return $base;
        }

        return $base . '/' . $path;
    }

    /**
     * Build headers and body for a custom JSON request.
     *
     * @param string               $url      The full URL.
     * @param array<string, mixed> $payload  The payload to JSON-encode.
     * @param string               $accept   The Accept header value.
     *
     * @return array{0: array<string, string>, 1: string}
     *
     * @throws StrandsException  If the payload cannot be JSON-encoded.
     */
    private function buildJsonRequest(string $url, array $payload, string $accept): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StrandsException(
                'Failed to encode request payload: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        // Middleware runs once per operation, not per retry attempt.
        // The modified headers/body are reused for all retry attempts.
        foreach ($this->middleware as $mw) {
            $result = $mw->beforeRequest($url, $headers, $body);
            $headers = $result['headers'];
            $body = $result['body'];
        }

        return [$headers, $body];
    }

    /**
     * Extract and decode JSON data from a single raw SSE event block.
     *
     * @return array<string, mixed>|null  The decoded data, or null if empty/malformed.
     */
    private static function extractSseData(string $rawEvent): ?array
    {
        $dataLines = [];

        // Per the SSE spec (https://html.spec.whatwg.org/multipage/server-sent-events.html):
        // - Lines starting with ":" are comments (used as heartbeats)
        // - "data:" lines carry the payload; multiple data lines are joined with "\n"
        foreach (explode("\n", $rawEvent) as $line) {
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, 5);
            }
        }

        $data = implode("\n", $dataLines);

        if ($data === '') {
            return null;
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Notify middleware of a completed operation (success or failure).
     *
     * Exceptions from middleware are caught and logged — never propagated.
     * This prevents observability middleware (tracing, metrics) from breaking
     * the caller's error handling or masking the original exception.
     */
    private function notifyAfterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
    {
        foreach ($this->middleware as $mw) {
            try {
                $mw->afterResponse($url, $statusCode, $durationMs, $error);
            } catch (\Throwable $e) {
                $this->logger->warning('Middleware afterResponse threw an exception', [
                    'middleware' => $mw::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @throws StrandsException
     */
    private static function detectTransport(): HttpTransport
    {
        if (class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return new SymfonyHttpTransport();
        }

        throw new StrandsException(
            'No HTTP transport available. Either install symfony/http-client '
            . 'for full support (invoke + streaming), or pass a PsrHttpTransport '
            . 'instance with your PSR-18 HTTP client to the StrandsClient constructor.',
        );
    }
}

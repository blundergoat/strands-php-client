<?php

declare(strict_types=1);

namespace StrandsPhpClient;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Context\AgentInput;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\StrandsException;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\Http\ResponseObserverNotifier;
use StrandsPhpClient\Http\SymfonyHttpTransport;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Usage;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * The primary client for interacting with Strands AI agents.
 *
 * Provides four entry points: invoke() for synchronous calls, stream() for
 * typed SSE streaming, postJson() and streamSse() for custom endpoints.
 * Handles auth, middleware, retry with backoff, and connection lifecycle.
 */
class StrandsClient
{
    /** Transport that performs the actual agent HTTP calls. */
    private HttpTransport $transport;

    /** Logger used to report client activity without exposing request bodies. */
    private LoggerInterface $logger;

    /** @var list<RequestMiddleware> */
    private readonly array $middleware;

    /** Fans parsed results out to every registered response observer. */
    private readonly ResponseObserverNotifier $observerNotifier;

    /**
     * Wire up the client for one agent.
     *
     * The app builds this (directly or via the Laravel/Symfony integration), then
     * calls invoke()/stream()/postJson()/streamSse() on it to talk to the agent.
     *
     * @param StrandsConfig              $config      Agent endpoint, auth, timeouts, retry settings.
     * @param HttpTransport|null         $transport   HTTP transport (auto-detected if null).
     * @param LoggerInterface|null       $logger      PSR-3 logger (NullLogger if null).
     * @param list<RequestMiddleware>    $middleware   Request middleware (executed in order).
     * @param list<ResponseObserver>     $responseObservers  Parsed response observers (executed in order).
     */
    public function __construct(
        private readonly StrandsConfig $config,
        ?HttpTransport $transport = null,
        ?LoggerInterface $logger = null,
        array $middleware = [],
        array $responseObservers = [],
    ) {
        $this->transport = $transport ?? self::detectTransport();
        $this->logger = $logger ?? new NullLogger();
        $this->middleware = $middleware;
        $this->observerNotifier = new ResponseObserverNotifier($middleware, $responseObservers, $this->logger);
    }

    /**
     * Send a message and wait for the complete response.
     *
     * @param string|AgentInput  $message         The message (or rich input) to send to the agent.
     * @param AgentContext|null  $context         Extra context (system prompts, metadata); null sends just the message.
     * @param string|null        $sessionId       Session to continue; null starts a brand-new conversation.
     * @param int|null           $timeoutSeconds  Per-request timeout override; null uses the configured default.
     *
     * @return AgentResponse parsed agent result the app can render or inspect.
     *
     * @throws \InvalidArgumentException  If timeoutSeconds is less than 1.
     */
    public function invoke(
        string|AgentInput $message,
        ?AgentContext $context = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
    ): AgentResponse {
        self::validateMessage($message);

        // The app may pass this from a per-request UI control; reject values that would never let the user get a response.
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }

        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/invoke';
        $startTime = hrtime(true);
        [$headers, $body] = $this->prepareAgentRequest(
            $url,
            $message,
            $context,
            $sessionId,
            'application/json',
            $startTime,
        );

        $this->logger->debug('Strands invoke request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        try {
            $data = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        $response = AgentResponse::fromArray($data);
        $durationMs = (hrtime(true) - $startTime) / 1e6;
        $this->observerNotifier->afterInvoke($url, $response, $durationMs);
        $this->notifyAfterResponse($url, 200, $durationMs);

        $this->logger->debug('Strands invoke response', [
            'agent' => $response->agent,
            'tools_used' => count($response->toolsUsed),
            'interrupted' => $response->isInterrupted(),
            'structured_output' => $response->structuredOutput !== null,
        ]);

        return $response;
    }

    /**
     * Send a message and receive the response as a real-time stream of events.
     *
     * @param string|AgentInput  $message         The message (or rich input) to send to the agent.
     * @param callable(StreamEvent): (void|bool) $onEvent  Called for each event as it arrives. Return false to cancel.
     * @param AgentContext|null  $context         Extra context; null sends just the message.
     * @param string|null        $sessionId       Session to continue; null starts a brand-new conversation.
     * @param int|null           $timeoutSeconds  Per-request timeout override; null uses the configured default.
     *
     * @return StreamResult stream summary the app can use after live updates finish.
     *
     * @throws Exceptions\StreamInterruptedException  If the stream ends without a terminal event.
     * @throws \InvalidArgumentException              If timeoutSeconds is less than 1.
     */
    public function stream(
        string|AgentInput $message,
        callable $onEvent,
        ?AgentContext $context = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
    ): StreamResult {
        self::validateMessage($message);

        // The app may bind this to a per-request UI control; reject values too small to ever answer.
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }

        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/stream';
        $startTime = hrtime(true);
        [$headers, $body] = $this->prepareAgentRequest(
            $url,
            $message,
            $context,
            $sessionId,
            'text/event-stream',
            $startTime,
        );

        $this->logger->debug('Strands stream request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        $streamParser = new StreamParser();
        $receivedTerminal = false;
        $accumulatedText = '';
        $textEvents = 0;
        $totalEvents = 0;
        /** @var int|null $firstTextTokenTime validated before app code uses it. */
        $firstTextTokenTime = null;
        /** @var StreamEvent|null $completeEvent validated before app code uses it. */
        $completeEvent = null;
        /** @var StreamEvent|null $terminalEvent validated before app code uses it. */
        $terminalEvent = null;
        $cancelled = false;
        /** @var list<array<string, mixed>> $citations validated before app code uses it. */
        $citations = [];

        try {
            $this->transport->stream($url, $headers, $body, $timeout, $this->config->connectTimeout, function (string $chunk) use ($streamParser, $onEvent, &$receivedTerminal, &$cancelled, &$accumulatedText, &$textEvents, &$totalEvents, &$firstTextTokenTime, &$completeEvent, &$terminalEvent, &$citations): bool {
                // A previous callback return false means the user or app has already stopped live updates.
                if ($cancelled) {
                    return false;
                }

                $events = $streamParser->feed($chunk);

                // One network chunk can carry several events; hand each to the app in order.
                foreach ($events as $event) {
                    $totalEvents++;

                    // Text events are what a chat UI would append token-by-token while the user waits.
                    if ($event->type === StreamEventType::Text && $event->text !== null) {
                        // Stamp the first token's arrival so we can report "time to first word".
                        if ($firstTextTokenTime === null) {
                            $firstTextTokenTime = hrtime(true);
                        }
                        $accumulatedText .= $event->text;
                        $textEvents++;
                    }

                    // Citation events let the app show source links beside the streamed answer.
                    if ($event->type === StreamEventType::Citation && $event->citation !== null) {
                        $citations[] = $event->citation;
                    }

                    // A terminal event means the agent is done (or errored) — remember that.
                    if ($event->isTerminal()) {
                        $receivedTerminal = true;
                        $terminalEvent = $event;

                        // Keep the Complete event; it carries the final usage, tools, and session id.
                        if ($event->type === StreamEventType::Complete) {
                            $completeEvent = $event;
                        }
                    }

                    // The callback can return false when the user clicks a stop button in the UI.
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

        // Stream ended with no "done" signal and the user didn't stop it — the
        // connection likely dropped mid-answer, so tell the caller it was interrupted.
        if (!$receivedTerminal && !$cancelled) {
            $streamInterruptedException = new Exceptions\StreamInterruptedException(
                sprintf(
                    'Stream to %s ended without a terminal event (complete or error). '
                . 'The connection may have dropped or the server closed the stream prematurely.',
                    $url,
                ),
            );
            $this->notifyAfterResponse($url, 0, $durationMs, $streamInterruptedException);

            throw $streamInterruptedException;
        }

        /** @var int|null $firstTextTokenTime validated before app code uses it. */
        /** @var list<array<string, mixed>> $citations validated before app code uses it. */
        $result = $this->buildStreamResult(
            $accumulatedText,
            $textEvents,
            $totalEvents,
            $cancelled,
            $startTime,
            $firstTextTokenTime,
            $completeEvent,
            $terminalEvent,
            $citations,
        );

        $this->observerNotifier->afterStream($url, $result, $durationMs);
        $this->notifyAfterResponse($url, $cancelled ? 0 : 200, $durationMs);
        $this->logSkippedEvents($streamParser);

        $this->logger->debug('Strands stream complete', [
            'text_events' => $result->textEvents,
            'total_events' => $result->totalEvents,
            'tools_used' => count($result->toolsUsed),
            'cancelled' => $result->cancelled,
        ]);

        return $result;
    }

    /**
     * Send a JSON POST to a custom endpoint path.
     *
     * Unlike invoke(), this accepts an arbitrary path and payload - useful for
     * agent endpoints with custom request/response schemas (file processing,
     * metadata extraction, etc.). Returns the raw decoded JSON array.
     *
     * @param string               $path     The endpoint path (e.g. '/file-summarise').
     * @param array<string, mixed> $payload  The JSON payload to send.
     * @param int|null             $timeout  Per-request timeout in seconds; null uses the configured default.
     *
     * @return array<string, mixed>  The decoded JSON response; empty only if the endpoint returned no fields.
     *
     * @throws StrandsException           If the request fails or the payload cannot be encoded.
     * @throws \InvalidArgumentException  If timeout is less than 1.
     */
    public function postJson(string $path, array $payload, ?int $timeout = null): array
    {
        // A per-request timeout the app may bind to a UI control; reject values too small to ever answer.
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        $url = $this->buildUrl($path);
        $startTime = hrtime(true);
        [$headers, $body] = $this->prepareJsonRequest($url, $payload, 'application/json', $startTime);

        $this->logger->debug('Strands postJson request', [
            'url' => $url,
            'path' => $path,
        ]);

        try {
            $data = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1e6;
            $statusCode = $e instanceof AgentErrorException ? $e->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $e);

            throw $e;
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;
        $this->observerNotifier->afterPostJson($url, $data, $durationMs);
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
     * raw decoded JSON arrays to the callback - preserving all fields including
     * domain-specific data that StreamEvent would discard.
     *
     * @param string               $path      The endpoint path (e.g. '/file-summarise-stream').
     * @param array<string, mixed> $payload   The JSON payload to send.
     * @param callable(array<string, mixed>): (void|bool) $onEvent  Called for each decoded SSE event. Return false to cancel.
     * @param int|null             $timeout   Per-request timeout in seconds; null uses the configured default.
     *
     * @return void No returned value; updates client or observer state.
     * @throws StrandsException           If the request fails or the payload cannot be encoded.
     * @throws \InvalidArgumentException  If timeout is less than 1.
     */
    public function streamSse(string $path, array $payload, callable $onEvent, ?int $timeout = null): void
    {
        // A per-request timeout the app may bind to a UI control; reject values too small to ever answer.
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        $url = $this->buildUrl($path);
        $startTime = hrtime(true);
        [$headers, $body] = $this->prepareJsonRequest($url, $payload, 'text/event-stream', $startTime);

        $this->logger->debug('Strands streamSse request', [
            'url' => $url,
            'path' => $path,
        ]);

        $effectiveTimeout = $timeout ?? $this->config->timeout;
        $buffer = '';
        $cancelled = false;
        $totalEvents = 0;
        $textEvents = 0;
        $terminalType = null;
        $usage = null;
        $stopReason = null;

        try {
            $this->transport->stream($url, $headers, $body, $effectiveTimeout, $this->config->connectTimeout, function (string $chunk) use (&$buffer, &$cancelled, &$totalEvents, &$textEvents, &$terminalType, &$usage, &$stopReason, $onEvent): bool {
                // A prior callback returning false already stopped the user's live updates.
                if ($cancelled) {
                    return false;
                }

                // Normalise line endings on the new chunk only.
                $buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);

                // Emit every complete event the buffer now holds; a partial tail waits for more.
                while (($position = strpos($buffer, "\n\n")) !== false) {
                    $rawEvent = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 2);

                    $decoded = self::extractSseData($rawEvent);

                    // Skip heartbeats/blank frames; only real decoded events reach the app.
                    if ($decoded !== null) {
                        $totalEvents++;
                        self::updateStreamSseSummary(
                            $decoded,
                            $textEvents,
                            $terminalType,
                            $usage,
                            $stopReason,
                        );

                        // The app returns false to stop early (e.g. the user cancelled).
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
        $streamSseSummary = new StreamSseSummary(
            totalEvents: $totalEvents,
            textEvents: $textEvents,
            cancelled: $cancelled,
            terminalType: $terminalType,
            usage: $usage,
            stopReason: $stopReason,
        );
        $this->observerNotifier->afterStreamSse($url, $streamSseSummary, $durationMs);
        $this->notifyAfterResponse($url, $cancelled ? 0 : 200, $durationMs);

        $this->logger->debug('Strands streamSse complete', [
            'url' => $url,
        ]);
    }

    /**
     * Send a POST request with exponential-backoff retry on transient errors.
     *
     * @param string $url agent endpoint the app is calling.
     * @param array<string, string> $headers headers that will reach the agent service.
     * @param string $body request body the agent service will receive.
     * @param int|null $timeout  Per-request timeout override (null = use config default).
     *
     * @return array<string, mixed> Decoded response the caller receives after retries.
     */
    private function postWithRetry(string $url, array $headers, string $body, ?int $timeout = null): array
    {
        $attempt = 0;
        $maxRetries = $this->config->maxRetries;
        $effectiveTimeout = $timeout ?? $this->config->timeout;

        // Keep trying until we return a response or run out of retry budget.
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

                // Out of retries — give up and let the user see the failure.
                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                // Exponential backoff with jitter (50-100% of base delay)
                // to avoid thundering herd when multiple clients retry simultaneously.
                // Capped at 30 seconds to prevent absurd delays at high retry counts.
                // random_int() (CSPRNG-backed) avoids the predictable lcg_value() PRNG
                // without changing the jitter window observed by retry tests/users.
                $baseDelay = min(
                    $this->config->retryDelayMs * (2 ** $attempt),
                    30_000,
                );
                $delayMs = (int) ($baseDelay * (random_int(50, 100) / 100));
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
     * Build a standard invoke/stream request and close middleware setup on late setup failure.
     *
     * @param string $url agent endpoint the app is calling.
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @param ?AgentContext $context Optional context for the turn; null sends just the message.
     * @param ?string $sessionId Conversation id to continue; null starts a brand-new conversation.
     * @param string $accept Accept header used for the app call.
     * @param int $startTime Request start timestamp used for duration metrics.
     * @return array{0: array<string, string>, 1: string} Final headers and JSON body sent to the agent.
     */
    private function prepareAgentRequest(
        string $url,
        string|AgentInput $message,
        ?AgentContext $context,
        ?string $sessionId,
        string $accept,
        int $startTime,
    ): array {
        $enteredMiddlewareCount = 0;

        try {
            return $this->buildRequest(
                $url,
                $message,
                $context,
                $sessionId,
                $accept,
                $enteredMiddlewareCount,
            );
        } catch (\Throwable $e) {
            $this->notifyAfterRequestSetupFailure($url, $startTime, $enteredMiddlewareCount, $e);

            throw $e;
        }
    }

    /**
     * Build a custom JSON/SSE request and close middleware setup on late setup failure.
     *
     * @param string $url agent endpoint the app is calling.
     * @param array<string, mixed> $payload Payload the app wants to send.
     * @param string $accept Accept header used for the app call.
     * @param int $startTime Request start timestamp used for duration metrics.
     * @return array{0: array<string, string>, 1: string} Final headers and JSON body sent to the agent.
     */
    private function prepareJsonRequest(string $url, array $payload, string $accept, int $startTime): array
    {
        $enteredMiddlewareCount = 0;

        try {
            return $this->buildJsonRequest($url, $payload, $accept, $enteredMiddlewareCount);
        } catch (\Throwable $e) {
            $this->notifyAfterRequestSetupFailure($url, $startTime, $enteredMiddlewareCount, $e);

            throw $e;
        }
    }

    /**
     * Notify exactly the middleware that entered beforeRequest() when request setup fails.
     *
     * @param string $url agent endpoint the app is calling.
     * @param int $startTime Request start timestamp used for duration metrics.
     * @param int $enteredMiddlewareCount How many middleware entered beforeRequest(); 0 means setup failed before any observed the operation.
     * @param \Throwable $error Failure surfaced while preparing the agent request.
     * @return void No returned value; closes middleware state for setup failures.
     */
    private function notifyAfterRequestSetupFailure(
        string $url,
        int $startTime,
        int $enteredMiddlewareCount,
        \Throwable $error,
    ): void {
        // A JSON encoding failure happens before middleware starts, so there is no operation to close.
        if ($enteredMiddlewareCount === 0) {
            return;
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;
        // Close out only the middleware that entered - the thrower included - so middleware the
        // operation never reached does not tear down state it never set up.
        $this->notifyAfterResponse(
            $url,
            0,
            $durationMs,
            $error,
            array_slice($this->middleware, 0, $enteredMiddlewareCount),
        );
    }

    /**
     * Builds the final stream summary after live updates finish.
     *
     * @param list<array<string, mixed>> $citations Citation blocks collected for the final answer UI.
     * @param string $accumulatedText Text streamed so far for the final app result.
     * @param int $textEvents Number of text updates shown during streaming.
     * @param int $totalEvents Total stream events received for the app call.
     * @param bool $cancelled Whether the app callback stopped the stream early.
     * @param int $startTime Request start timestamp used for duration metrics.
     * @param ?int $firstTextTokenTime Timestamp for first-token latency; null when no text arrived (cancelled/error-only).
     * @param ?StreamEvent $completeEvent Final Complete event; null when the stream ended without one (error/cancel/drop).
     * @param ?StreamEvent $terminalEvent Final terminal event; null when no terminal event arrived.
     * @return StreamResult stream summary the app can use after live updates finish.
     */
    private function buildStreamResult(
        string $accumulatedText,
        int $textEvents,
        int $totalEvents,
        bool $cancelled,
        int $startTime,
        ?int $firstTextTokenTime,
        ?StreamEvent $completeEvent,
        ?StreamEvent $terminalEvent,
        array $citations = [],
    ): StreamResult {
        $ttftMs = $firstTextTokenTime !== null
            ? ($firstTextTokenTime - $startTime) / 1e6
            : null;

        // Extract fields from the Complete event, falling back to safe
        // defaults when the stream ended without one (e.g. Error event,
        // user cancellation, or an interrupted connection).
        $sessionId = null;
        $usage = new Usage();
        $toolsUsed = [];
        $stopReason = null;
        $interrupts = [];
        $guardrailTrace = null;
        $finalText = $accumulatedText;
        $contextSize = null;
        $projectedContextSize = null;
        $terminalType = $terminalEvent?->type->value;
        $errorCode = $terminalEvent?->type === StreamEventType::Error ? $terminalEvent->errorCode : null;
        $errorMessage = $terminalEvent?->type === StreamEventType::Error ? $terminalEvent->errorMessage : null;

        // A Complete event arrived, so fill the final result from it; otherwise the
        // safe defaults above stand (the stream errored, was cancelled, or dropped).
        if ($completeEvent !== null) {
            $sessionId = $completeEvent->sessionId;
            $usage = Usage::fromArray($completeEvent->usage);
            $toolsUsed = $completeEvent->toolsUsed;

            // Prefer the text we streamed live; fall back to the full text if we saw no tokens.
            if ($finalText === '') {
                $finalText = $completeEvent->fullText ?? '';
            }

            // Map the raw stop reason to a typed enum the app can branch on.
            if (is_string($completeEvent->stopReason)) {
                $stopReason = Response\StopReason::tryFrom($completeEvent->stopReason);
            }

            // Each interrupt becomes an approval/prompt the app must surface to the user.
            foreach ($completeEvent->interrupts as $interruptData) {
                $interrupts[] = Response\InterruptDetail::fromArray($interruptData);
            }

            // A guardrail trace means the app should explain a safety intervention.
            if ($completeEvent->guardrailTrace !== null) {
                $guardrailTrace = Response\GuardrailTrace::fromArray($completeEvent->guardrailTrace);
            }

            $contextSize = $completeEvent->contextSize;
            $projectedContextSize = $completeEvent->projectedContextSize;
        }

        return new StreamResult(
            text: $finalText,
            sessionId: $sessionId,
            usage: $usage,
            toolsUsed: $toolsUsed,
            textEvents: $textEvents,
            totalEvents: $totalEvents,
            stopReason: $stopReason,
            cancelled: $cancelled,
            timeToFirstTextTokenMs: $ttftMs,
            interrupts: $interrupts,
            guardrailTrace: $guardrailTrace,
            citations: $citations,
            contextSize: $contextSize,
            projectedContextSize: $projectedContextSize,
            terminalType: $terminalType,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Log stream parser events skipped for forward compatibility.
     *
     * @param StreamParser $streamParser Parser that tracked skipped stream events.
     * @return void
     */
    private function logSkippedEvents(StreamParser $streamParser): void
    {
        $skippedEvents = $streamParser->getSkippedEvents();
        // The server sent event types this client didn't recognise — log it as a hint
        // that the PHP client may be behind the agent, without disrupting the user.
        if ($skippedEvents > 0) {
            $this->logger->info('strands.stream.skipped_events', [
                'count' => $skippedEvents,
                'hint' => 'Unknown event types from agent - may need PHP client update',
            ]);
        }
    }

    /**
     * Builds the HTTP request sent for the caller action.
     *
     * @param string $url agent endpoint the app is calling.
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @param ?AgentContext $context Optional context for the turn; null sends just the message.
     * @param ?string $sessionId Conversation id to continue; null starts a brand-new conversation.
     * @param string $accept Accept header used for the app call.
     * @param int $enteredMiddlewareCount Incremented as each middleware enters beforeRequest(); stays 0 when setup fails before any middleware ran.
     * @return array{0: array<string, string>, 1: string} Final headers and JSON body sent to the agent.
     *
     * @throws StrandsException  If the payload cannot be JSON-encoded.
     */
    private function buildRequest(
        string $url,
        string|AgentInput $message,
        ?AgentContext $context,
        ?string $sessionId,
        string $accept,
        int &$enteredMiddlewareCount,
    ): array {
        // Rich input means the user attached files, images, or asked for structured output.
        if ($message instanceof AgentInput) {
            $payload = ['message' => $message->toPayloadValue()];
        } else {
            $payload = ['message' => $message];
        }

        // A session id means the user is continuing an existing conversation.
        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }

        // Context carries app-provided instructions, metadata, or documents for this turn.
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

        // Middleware runs before auth so that body-modifying middleware
        // (e.g. payload enrichment) doesn't invalidate SigV4 signatures.
        foreach ($this->middleware as $mw) {
            // Counted before the call so a middleware that throws here still gets its teardown.
            $enteredMiddlewareCount++;
            $result = $mw->beforeRequest($url, $headers, $body);
            $headers = $result['headers'];
            $body = $result['body'];
        }

        // Auth runs last - after middleware mutations - so signatures
        // cover the final headers and body that will actually be sent.
        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        return [$headers, $body];
    }

    /**
     * Build the full URL for a custom endpoint path.
     *
     * @param string $path request path that becomes part of the signed URL.
     * @return string Full absolute URL for the custom-endpoint call.
     */
    private function buildUrl(string $path): string
    {
        $base = rtrim($this->config->endpoint, '/');
        $path = ltrim($path, '/');

        // An empty path means the app wants the agent's base URL itself.
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
     * @param int                  $enteredMiddlewareCount Incremented as each middleware enters beforeRequest(); stays 0 when setup fails before any middleware ran.
     *
     * @return array{0: array<string, string>, 1: string} Final headers and JSON body sent to the custom endpoint.
     *
     * @throws StrandsException  If the payload cannot be JSON-encoded.
     */
    private function buildJsonRequest(string $url, array $payload, string $accept, int &$enteredMiddlewareCount): array
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

        // Middleware runs before auth so that body-modifying middleware
        // doesn't invalidate SigV4 signatures.
        foreach ($this->middleware as $mw) {
            // Counted before the call so a middleware that throws here still gets its teardown.
            $enteredMiddlewareCount++;
            $result = $mw->beforeRequest($url, $headers, $body);
            $headers = $result['headers'];
            $body = $result['body'];
        }

        // Auth runs last - signatures cover the final request.
        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        return [$headers, $body];
    }

    /**
     * Extract and decode JSON data from a single raw SSE event block.
     *
     * @param string $rawEvent Raw SSE event block received from the stream.
     * @return array<string, mixed>|null  The decoded data, or null if empty/malformed.
     */
    private static function extractSseData(string $rawEvent): ?array
    {
        $dataLines = [];

        // Per the SSE spec (https://html.spec.whatwg.org/multipage/server-sent-events.html):
        // - Lines starting with ":" are comments (used as heartbeats)
        // - "data:" lines carry the payload; multiple data lines are joined with "\n"
        foreach (explode("\n", $rawEvent) as $line) {
            // Lines starting with ":" are heartbeat/comment lines — nothing to display.
            if (str_starts_with($line, ':')) {
                continue;
            }

            // The actual payload rides on "data:" lines (with or without the space).
            if (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, 5);
            }
        }

        $data = implode("\n", $dataLines);

        // A comment-only frame (e.g. a keep-alive) carries no data — nothing to emit.
        if ($data === '') {
            return null;
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // A payload that isn't a JSON object can't be handed to the app callback.
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded validated before app code uses it. */
        return $decoded;
    }

    /**
     * Update a sanitized summary from one raw streamSse() event.
     *
     * This intentionally reads only known-safe control fields. Raw custom
     * endpoint payloads remain app-owned and are not inspected for telemetry.
     *
     * @param array<string, mixed> $event decoded SSE event delivered to the app callback.
     * @param int $textEvents running count of text events emitted to the app.
     * @param ?string $terminalType Last complete/error event type seen; null until the stream ends.
     * @param ?Usage $usage Token usage from the terminal event; null until one reports it.
     * @param ?string $stopReason Finish reason from the terminal event; null until one reports it.
     * @return void No returned value; updates the stream summary used by observers.
     */
    private static function updateStreamSseSummary(
        array $event,
        int &$textEvents,
        ?string &$terminalType,
        ?Usage &$usage,
        ?string &$stopReason,
    ): void {
        $type = $event['type'] ?? null;
        // An event with no string type tells us nothing to summarise; ignore it.
        if (!is_string($type)) {
            return;
        }

        // Count text events so telemetry can report how much the user actually saw.
        if ($type === 'text') {
            $textEvents++;
        }

        // Remember the terminal type so observers know how the stream ended.
        if ($type === 'complete' || $type === 'error') {
            $terminalType = $type;
        }

        $rawUsage = $event['usage'] ?? null;
        // Capture token usage when the event carries it, for the app's cost readout.
        if (is_array($rawUsage)) {
            /** @var array<string, mixed> $rawUsage validated before app code uses it. */
            $usage = Usage::fromArray($rawUsage);
        }

        $rawStopReason = $event['stop_reason'] ?? null;
        // Record why the agent stopped, when the event says so.
        if (is_string($rawStopReason)) {
            $stopReason = $rawStopReason;
        }
    }

    /**
     * Notify middleware of a completed operation (success or failure).
     *
     * Exceptions from middleware are caught and logged - never propagated.
     * This prevents observability middleware (tracing, metrics) from breaking
     * the caller's error handling or masking the original exception.
     *
     * @param string $url agent endpoint the app is calling.
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param float $durationMs elapsed time reported to app telemetry.
     * @param ?\Throwable $error Failure surfaced while awaiting the agent; null when the call succeeded.
     * @param list<RequestMiddleware>|null $middlewareToNotify Middleware receiving the teardown; null notifies every configured middleware (the normal end-of-operation path).
     * @return void No returned value; updates client or observer state.
     */
    private function notifyAfterResponse(
        string $url,
        int $statusCode,
        float $durationMs,
        ?\Throwable $error = null,
        ?array $middlewareToNotify = null,
    ): void {
        // Tell each notified middleware the call is done; observability failures are logged, not raised.
        foreach ($middlewareToNotify ?? $this->middleware as $mw) {
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
     * Reject empty messages early to avoid a confusing 400 from the API.
     *
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @return void No returned value; updates client or observer state.
     * @throws \InvalidArgumentException  If the message text is empty.
     */
    private static function validateMessage(string|AgentInput $message): void
    {
        $text = $message instanceof AgentInput ? $message->getText() : $message;

        // Block an empty send (e.g. the user hit enter on a blank box) unless attachments
        // or content blocks carry the request — saves a confusing 400 round-trip.
        if ($text === '' && !($message instanceof AgentInput && $message->toPayloadValue() !== '')) {
            throw new \InvalidArgumentException(
                'Message cannot be empty. Provide a non-empty string or an AgentInput with content blocks.',
            );
        }
    }

    /**
     * Chooses the HTTP transport used to reach the agent.
     *
     * @return HttpTransport A ready transport (Symfony-based) for reaching the agent.
     * @throws StrandsException When no supported HTTP client is installed.
     */
    private static function detectTransport(): HttpTransport
    {
        // Prefer Symfony's client when installed — it's the only transport that can stream.
        if (class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return new SymfonyHttpTransport();
        }

        throw new StrandsException(
            'No HTTP transport available. Install symfony/http-client (recommended) '
            . 'for full invoke + streaming support, or pass a PsrHttpTransport instance '
            . 'with your PSR-18 client to the StrandsClient constructor (invoke only, no streaming).',
        );
    }
}

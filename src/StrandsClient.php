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
use StrandsPhpClient\Streaming\SseFrameDecoder;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Sends user requests to a Strands HTTP wrapper and turns Wire Contract v1 responses into app-facing results.
 *
 * Use invoke() for one complete answer, stream() for typed live updates, and postJson() or streamSse() for app-owned custom routes.
 * The client applies authentication, middleware, response observers, retry policy, cancellation, and safe request logging around those entry points.
 *
 * It consumes the Strands HTTP Wire Contract rather than raw strands-agents SDK TypedDict shapes.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") -- request, retry, streaming, and observer ordering stay in one client boundary.
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
     * Connects an app to an agent endpoint; use it directly or through a framework for standard and custom requests.
     *
     * @param StrandsConfig              $config      Agent endpoint, auth, timeouts, retry settings.
     * @param HttpTransport|null         $transport   Transport to call the agent; null auto-detects Symfony HTTP or throws setup guidance.
     * @param LoggerInterface|null       $logger      App logger; null uses NullLogger, so requests work without emitting logs.
     * @param list<RequestMiddleware>    $middleware Request middleware in call order; empty means no request mutation or middleware teardown.
     * @param list<ResponseObserver>     $responseObservers Parsed response observers in call order; empty means no result-level telemetry callbacks.
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
     * Sends one user turn and waits for a complete typed answer; use it when the caller does not need live updates.
     *
     * @param string|AgentInput  $message         User text or rich input; an empty string or content-free AgentInput is rejected before HTTP.
     * @param AgentContext|null  $context         Extra context (system prompts, metadata); null sends just the message.
     * @param string|null        $sessionId       Session to continue; null starts a brand-new conversation.
     * @param int|null           $timeoutSeconds  Per-request timeout override; null uses the configured default.
     *
     * @return AgentResponse Parsed result the app can render or inspect; never null.
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

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }

        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/invoke';
        $startTimeNs = hrtime(true);
        [$headers, $body] = $this->prepareAgentRequest(
            $url,
            $message,
            $context,
            $sessionId,
            'application/json',
            $startTimeNs,
        );

        $this->logger->debug('Strands invoke request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        try {
            $responseData = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $exception) {
            // For example, the agent may time out or return a structured HTTP error; close observers before the app receives that same failure.
            $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
            $statusCode = $exception instanceof AgentErrorException ? $exception->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $exception);

            throw $exception;
        }

        $response = AgentResponse::fromArray($responseData);
        $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
        $this->observerNotifier->afterInvoke($url, $response, $durationMs);
        $this->notifyAfterResponse($url, 200, $durationMs);

        // A null structured result logs false, distinguishing plain text or another response shape.
        $this->logger->debug('Strands invoke response', [
            'session_id' => $response->sessionId,
            'agent' => $response->agent,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'tools_used' => count($response->toolsUsed),
            'interrupted' => $response->isInterrupted(),
            'structured_output' => $response->structuredOutput !== null,
        ]);

        return $response;
    }

    /**
     * Streams typed updates for one turn; return false from onEvent when the caller wants to cancel.
     *
     * @param string|AgentInput  $message         User text or rich input; an empty string or content-free AgentInput is rejected before HTTP.
     * @param callable(StreamEvent): (void|bool) $onEvent Called for each event; false cancels, while true or no return value keeps updates flowing.
     * @param AgentContext|null  $context         Extra context; null sends just the message.
     * @param string|null        $sessionId       Session to continue; null starts a brand-new conversation.
     * @param int|null           $timeoutSeconds  Per-request timeout override; null uses the configured default.
     *
     * @return StreamResult Final stream summary; text and collections may be empty after cancellation or an error-only terminal event.
     *
     * @throws Exceptions\StreamInterruptedException  If the stream ends without a terminal event.
     * @throws \InvalidArgumentException              If timeoutSeconds is less than 1.
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength") -- callback state stays together so the app receives one consistent final result.
     */
    public function stream(
        string|AgentInput $message,
        callable $onEvent,
        ?AgentContext $context = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
    ): StreamResult {
        self::validateMessage($message);
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1');
        }
        $timeout = $timeoutSeconds ?? $this->config->timeout;
        $url = rtrim($this->config->endpoint, '/') . '/stream';
        $startTimeNs = hrtime(true);
        [$headers, $body] = $this->prepareAgentRequest(
            $url,
            $message,
            $context,
            $sessionId,
            'text/event-stream',
            $startTimeNs,
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
        /** @var int|null $firstTextTokenTimeNs validated before app code uses it. */
        $firstTextTokenTimeNs = null;
        /** @var StreamEvent|null $completeEvent validated before app code uses it. */
        $completeEvent = null;
        /** @var StreamEvent|null $terminalEvent validated before app code uses it. */
        $terminalEvent = null;
        $cancelled = false;
        /** @var list<array<string, mixed>> $citations validated before app code uses it. */
        $citations = [];

        try {
            $this->transport->stream(
                $url,
                $headers,
                $body,
                $timeout,
                $this->config->connectTimeout,
                function (string $chunk) use (
                    $streamParser,
                    $onEvent,
                    &$receivedTerminal,
                    &$cancelled,
                    &$accumulatedText,
                    &$textEvents,
                    &$totalEvents,
                    &$firstTextTokenTimeNs,
                    &$completeEvent,
                    &$terminalEvent,
                    &$citations,
                ): bool {
                    // Once a callback cancels, every later transport callback must stop immediately.
                    if ($cancelled) {
                        return false;
                    }

                    $streamEvents = $streamParser->feed($chunk);

                    // Deliver complete updates in arrival order so the callback observes the wire sequence.
                    foreach ($streamEvents as $streamEvent) {
                        $totalEvents++;

                        // Text events build the final result and establish first-text latency.
                        if ($streamEvent->type === StreamEventType::Text && $streamEvent->text !== null) {
                            // Record the first text event once so the result can report TTFT.
                            if ($firstTextTokenTimeNs === null) {
                                $firstTextTokenTimeNs = hrtime(true);
                            }
                            $accumulatedText .= $streamEvent->text;
                            $textEvents++;
                        }

                        // Citation events are retained for the final source list as well as sent to the live callback.
                        if ($streamEvent->type === StreamEventType::Citation && $streamEvent->citation !== null) {
                            $citations[] = $streamEvent->citation;
                        }

                        // A complete or error event proves the wrapper ended the stream deliberately rather than dropping the connection.
                        if ($streamEvent->isTerminal()) {
                            $receivedTerminal = true;
                            $terminalEvent = $streamEvent;

                            // Keep the Complete event; it carries the final usage, tools, and session id.
                            if ($streamEvent->type === StreamEventType::Complete) {
                                $completeEvent = $streamEvent;
                            }
                        }

                        if ($onEvent($streamEvent) === false) {
                            $cancelled = true;

                            return false;
                        }
                    }

                    return true;
                },
            );
        } catch (\Throwable $exception) {
            // For example, a network drop or malformed oversized frame may interrupt live output; close observers before surfacing it to the app.
            $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
            $statusCode = $exception instanceof AgentErrorException ? $exception->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $exception);

            throw $exception;
        }

        $durationMs = (hrtime(true) - $startTimeNs) / 1e6;

        // Without a terminal event or callback cancellation, the connection likely dropped mid-answer.
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

        /** @var int|null $firstTextTokenTimeNs validated before app code uses it. */
        /** @var list<array<string, mixed>> $citations validated before app code uses it. */
        $streamResult = $this->buildStreamResult(
            accumulatedText: $accumulatedText,
            textEvents: $textEvents,
            totalEvents: $totalEvents,
            wasCancelled: $cancelled,
            startTimeNs: $startTimeNs,
            firstTextTokenTimeNs: $firstTextTokenTimeNs,
            completeEvent: $completeEvent,
            terminalEvent: $terminalEvent,
            citations: $citations,
        );

        $this->observerNotifier->afterStream($url, $streamResult, $durationMs);
        $this->notifyAfterResponse($url, $cancelled ? 0 : 200, $durationMs);
        $this->logSkippedEvents($streamParser);

        $this->logger->debug('Strands stream complete', [
            'session_id' => $streamResult->sessionId,
            'text_events' => $streamResult->textEvents,
            'total_events' => $streamResult->totalEvents,
            'text_length' => strlen($streamResult->text),
            'input_tokens' => $streamResult->usage->inputTokens,
            'output_tokens' => $streamResult->usage->outputTokens,
            'ttft_ms' => $streamResult->timeToFirstTextTokenMs,
            'tools_used' => count($streamResult->toolsUsed),
            'cancelled' => $streamResult->cancelled,
        ]);

        return $streamResult;
    }

    /**
     * Posts JSON to an app-owned route; use it for raw file-processing or metadata results instead of invoke().
     *
     * @param string               $path     Custom route; empty targets the configured base endpoint.
     * @param array<string, mixed> $payload  App-owned JSON payload; an empty map is sent as an empty JSON object.
     * @param int|null             $timeout  Per-request timeout in seconds; null uses the configured default.
     *
     * @return array<string, mixed>  The decoded JSON response; empty only if the endpoint returned no fields.
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
        $startTimeNs = hrtime(true);
        [$headers, $body] = $this->prepareJsonRequest($url, $payload, 'application/json', $startTimeNs);

        $this->logger->debug('Strands postJson request', [
            'url' => $url,
            'path' => $path,
        ]);

        try {
            $responseData = $this->postWithRetry($url, $headers, $body, $timeout);
        } catch (\Throwable $exception) {
            // For example, an app-owned analysis route may reject its payload or time out; preserve that failure after observer teardown.
            $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
            $statusCode = $exception instanceof AgentErrorException ? $exception->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $exception);

            throw $exception;
        }

        $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
        $this->observerNotifier->afterPostJson($url, $responseData, $durationMs);
        $this->notifyAfterResponse($url, 200, $durationMs);

        $this->logger->debug('Strands postJson response', [
            'url' => $url,
        ]);

        return $responseData;
    }

    /**
     * Streams an app-owned SSE schema; use it when the caller needs every field instead of the typed StreamEvent projection.
     *
     * @param string               $path      Custom route; empty targets the configured base endpoint.
     * @param array<string, mixed> $payload   App-owned JSON payload; an empty map is sent as an empty JSON object.
     * @param callable(array<string, mixed>): (void|bool) $onEvent Called for each decoded object; false cancels, while true or no return continues.
     * @param int|null             $timeout   Per-request timeout in seconds; null uses the configured default.
     *
     * @return void No final result; the callback receives events and observers receive the sanitized completion summary.
     * @throws StrandsException           If the request fails or the payload cannot be encoded.
     * @throws \InvalidArgumentException  If timeout is less than 1.
     */
    public function streamSse(string $path, array $payload, callable $onEvent, ?int $timeout = null): void
    {
        if ($timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1');
        }

        $url = $this->buildUrl($path);
        $startTimeNs = hrtime(true);
        [$headers, $body] = $this->prepareJsonRequest($url, $payload, 'text/event-stream', $startTimeNs);

        $this->logger->debug('Strands streamSse request', [
            'url' => $url,
            'path' => $path,
        ]);

        $effectiveTimeoutSeconds = $timeout ?? $this->config->timeout;
        $frameDecoder = new SseFrameDecoder();
        $cancelled = false;
        $totalEvents = 0;
        $textEvents = 0;
        $terminalType = null;
        $usage = null;
        $stopReason = null;

        try {
            $this->transport->stream(
                $url,
                $headers,
                $body,
                $effectiveTimeoutSeconds,
                $this->config->connectTimeout,
                function (string $chunk) use (
                    &$cancelled,
                    &$totalEvents,
                    &$textEvents,
                    &$terminalType,
                    &$usage,
                    &$stopReason,
                    $frameDecoder,
                    $onEvent,
                ): bool {
                    // Once a callback cancels, every later transport callback must stop immediately.
                    if ($cancelled) {
                        return false;
                    }

                    // Process complete raw frames in order so the callback observes the wrapper's exact event sequence.
                    foreach ($frameDecoder->feed($chunk) as $rawSseFrame) {
                        $decodedSseEvent = self::extractSseData($rawSseFrame);

                        // Skip heartbeats/blank frames; only real decoded events reach the app.
                        if ($decodedSseEvent !== null) {
                            $totalEvents++;
                            self::updateStreamSseSummary(
                                $decodedSseEvent,
                                $textEvents,
                                $terminalType,
                                $usage,
                                $stopReason,
                            );

                            if ($onEvent($decodedSseEvent) === false) {
                                $cancelled = true;

                                return false;
                            }
                        }
                    }

                    return true;
                },
            );
        } catch (\Throwable $exception) {
            // For example, the custom endpoint may disconnect mid-event; close observers before returning the transport/parser failure to the app.
            $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
            $statusCode = $exception instanceof AgentErrorException ? $exception->statusCode : 0;
            $this->notifyAfterResponse($url, $statusCode, $durationMs, $exception);

            throw $exception;
        }

        // A caller-cancelled stream reports status 0 to observers; natural completion reports 200.
        $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
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
     * Retries transient POST failures; use it behind invoke() and postJson() while returning caller input errors immediately.
     *
     * @param string $url agent endpoint the app is calling.
     * @param array<string, string> $headers headers that will reach the agent service.
     * @param string $body request body the agent service will receive.
     * @param int|null $timeout Per-request timeout; null uses the configured default.
     *
     * @return array<string, mixed> Decoded response after retries; empty means the endpoint returned an empty JSON object.
     */
    private function postWithRetry(string $url, array $headers, string $body, ?int $timeout = null): array
    {
        $retryAttempt = 0;
        $maxRetries = $this->config->maxRetries;
        $effectiveTimeoutSeconds = $timeout ?? $this->config->timeout;

        // Keep trying until we return a response or run out of retry budget.
        while (true) {
            try {
                return $this->transport->post($url, $headers, $body, $effectiveTimeoutSeconds, $this->config->connectTimeout);
            } catch (StrandsException $exception) {
                // A 429 or transient gateway error may recover; return non-retryable client errors immediately.
                if (
                    $exception instanceof AgentErrorException
                    && !in_array($exception->statusCode, $this->config->retryableStatusCodes, true)
                ) {
                    throw $exception;
                }

                // The retry budget is exhausted, so return the failure to the caller.
                if ($retryAttempt >= $maxRetries) {
                    throw $exception;
                }

                // Back off exponentially so simultaneous app requests do not all retry the agent at once.
                //
                // - Randomize each wait to 50–100% of its base delay with random_int().
                // - Cap the wait at 30 seconds so the caller never inherits an excessive retry pause.
                $baseDelayMs = min(
                    $this->config->retryDelayMs * (2 ** $retryAttempt),
                    30_000,
                );
                $delayMs = (int) ($baseDelayMs * (random_int(50, 100) / 100));
                $retryAttempt++;

                $this->logger->warning('Strands request failed, retrying', [
                    'attempt' => $retryAttempt,
                    'max_retries' => $maxRetries,
                    'delay_ms' => $delayMs,
                    'error' => $exception->getMessage(),
                ]);

                usleep($delayMs * 1000);
            }
        }
    }

    /**
     * Prepares a standard request; use it before transport so setup failures close only middleware that already entered.
     *
     * @param string $url agent endpoint the app is calling.
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @param ?AgentContext $context Optional context for the turn; null sends just the message.
     * @param ?string $sessionId Conversation id to continue; null starts a brand-new conversation.
     * @param string $accept Accept header used for the app call.
     * @param int $startTimeNs Request start timestamp in nanoseconds, used for duration metrics.
     * @return array{0: array<string, string>, 1: string} Two-item request tuple; it is never empty and contains final headers plus JSON.
     */
    private function prepareAgentRequest(
        string $url,
        string|AgentInput $message,
        ?AgentContext $context,
        ?string $sessionId,
        string $accept,
        int $startTimeNs,
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
        } catch (\Throwable $exception) {
            // For example, request middleware may fail after tracing starts; close only the middleware that already entered before rethrowing.
            $this->notifyAfterRequestSetupFailure($url, $startTimeNs, $enteredMiddlewareCount, $exception);

            throw $exception;
        }
    }

    /**
     * Prepares a custom request; use it before transport so empty JSON stays explicit and entered middleware closes on failure.
     *
     * @param string $url agent endpoint the app is calling.
     * @param array<string, mixed> $payload App payload; an empty map is encoded as an explicit empty JSON object.
     * @param string $accept Accept header used for the app call.
     * @param int $startTimeNs Request start timestamp in nanoseconds, used for duration metrics.
     * @return array{0: array<string, string>, 1: string} Two-item request tuple; it is never empty and contains final headers plus JSON.
     */
    private function prepareJsonRequest(string $url, array $payload, string $accept, int $startTimeNs): array
    {
        $enteredMiddlewareCount = 0;

        try {
            return $this->buildJsonRequest($url, $payload, $accept, $enteredMiddlewareCount);
        } catch (\Throwable $exception) {
            // For example, custom-route middleware may fail after opening a span; close entered middleware before the app receives the setup error.
            $this->notifyAfterRequestSetupFailure($url, $startTimeNs, $enteredMiddlewareCount, $exception);

            throw $exception;
        }
    }

    /**
     * Closes entered middleware; use it after request setup fails, while a zero count means no lifecycle needs closing.
     *
     * @param string $url agent endpoint the app is calling.
     * @param int $startTimeNs Request start timestamp in nanoseconds, used for duration metrics.
     * @param int $enteredMiddlewareCount How many middleware entered beforeRequest(); 0 means setup failed before any observed the operation.
     * @param \Throwable $error Failure surfaced while preparing the agent request.
     * @return void No returned value; closes middleware state for setup failures.
     */
    private function notifyAfterRequestSetupFailure(
        string $url,
        int $startTimeNs,
        int $enteredMiddlewareCount,
        \Throwable $error,
    ): void {
        // A JSON encoding failure happens before middleware starts, so there is no operation to close.
        if ($enteredMiddlewareCount === 0) {
            return;
        }

        $durationMs = (hrtime(true) - $startTimeNs) / 1e6;
        // Close only middleware that entered, including the thrower, so untouched middleware never tears down state it did not create.
        $this->notifyAfterResponse(
            $url,
            0,
            $durationMs,
            $error,
            array_slice($this->middleware, 0, $enteredMiddlewareCount),
        );
    }

    /**
     * Builds the final stream result after completion or cancellation, keeping absent terminal data null, empty, or zero.
     *
     * @param list<array<string, mixed>> $citations Citation blocks collected for the final result.
     * @param string $accumulatedText Text streamed so far for the final app result.
     * @param int $textEvents Number of text updates received during streaming.
     * @param int $totalEvents Total stream events received for the app call.
     * @param bool $wasCancelled Whether the app callback stopped the stream early.
     * @param int $startTimeNs Request start timestamp in nanoseconds, used for duration metrics.
     * @param ?int $firstTextTokenTimeNs First-text timestamp in nanoseconds; null when no text arrived (cancelled/error-only).
     * @param ?StreamEvent $completeEvent Final Complete event; null when the stream ended without one (error/cancel/drop).
     * @param ?StreamEvent $terminalEvent Final terminal event; null when no terminal event arrived.
     * @return StreamResult stream summary the app can use after live updates finish.
     */
    private function buildStreamResult(
        string $accumulatedText,
        int $textEvents,
        int $totalEvents,
        bool $wasCancelled,
        int $startTimeNs,
        ?int $firstTextTokenTimeNs,
        ?StreamEvent $completeEvent,
        ?StreamEvent $terminalEvent,
        array $citations = [],
    ): StreamResult {
        // Without a text event, the result has no time-to-first-text measurement.
        $ttftMs = $firstTextTokenTimeNs !== null
            ? ($firstTextTokenTimeNs - $startTimeNs) / 1e6
            : null;

        // Start with safe empty values because an error, cancellation, or dropped connection may leave the app without a Complete event.
        $sessionId = null;
        $usage = new Usage();
        $toolsUsed = [];
        $stopReason = null;
        $rawStopReason = null;
        $interrupts = [];
        $guardrailTrace = null;
        $finalText = $accumulatedText;
        $contextSize = null;
        $projectedContextSize = null;
        $terminalType = $terminalEvent?->type->value;
        // Only a terminal Error event supplies caller-visible error detail; every other ending keeps these fields null.
        $errorCode = $terminalEvent?->type === StreamEventType::Error ? $terminalEvent->errorCode : null;
        $errorMessage = $terminalEvent?->type === StreamEventType::Error ? $terminalEvent->errorMessage : null;

        // A Complete event fills the final result; otherwise safe defaults describe an error, cancellation, or dropped connection.
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
                $rawStopReason = $completeEvent->stopReason;
                $stopReason = Response\StopReason::tryFrom($rawStopReason);
            }

            // Each interrupt becomes an approval or follow-up action for the caller.
            foreach ($completeEvent->interrupts as $interruptData) {
                $interrupts[] = Response\InterruptDetail::fromArray($interruptData);
            }

            // A guardrail trace preserves the safety intervention details for the caller.
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
            cancelled: $wasCancelled,
            timeToFirstTextTokenMs: $ttftMs,
            interrupts: $interrupts,
            guardrailTrace: $guardrailTrace,
            citations: $citations,
            contextSize: $contextSize,
            projectedContextSize: $projectedContextSize,
            terminalType: $terminalType,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawStopReason: $rawStopReason,
        );
    }

    /**
     * Logs skipped future or malformed events; use it after typed streaming, while zero skipped events produce no entry.
     *
     * @param StreamParser $streamParser Parser that tracked skipped stream events.
     * @return void
     */
    private function logSkippedEvents(StreamParser $streamParser): void
    {
        $skippedEvents = $streamParser->getSkippedEvents();
        // Unknown server events suggest this client may be behind the agent, so log an upgrade hint without disrupting the request.
        if ($skippedEvents > 0) {
            $this->logger->info('strands.stream.skipped_events', [
                'count' => $skippedEvents,
                'hint' => 'Unknown event types from agent - may need PHP client update',
            ]);
        }
    }

    /**
     * Encodes and authenticates a standard turn; use it after validation, with null context/session fields omitted.
     *
     * @param string $url agent endpoint the app is calling.
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @param ?AgentContext $context Optional context for the turn; null sends just the message.
     * @param ?string $sessionId Conversation id to continue; null starts a brand-new conversation.
     * @param string $accept Accept header used for the app call.
     * @param int $enteredMiddlewareCount Incremented as each middleware enters beforeRequest(); stays 0 when setup fails before any middleware ran.
     * @return array{0: array<string, string>, 1: string} Two-item request tuple; it is never empty and contains final headers plus JSON.
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
        // Rich input carries attachments, interrupts, cache points, or structured-output instructions.
        if ($message instanceof AgentInput) {
            $payload = ['message' => $message->toPayloadValue()];
        } else {
            $payload = ['message' => $message];
        }

        // A session ID continues an existing conversation.
        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }

        // Context carries app-provided instructions, metadata, or documents for this turn.
        if ($context !== null) {
            $payload['context'] = $context->toArray();
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // For example, an attachment metadata value may contain invalid UTF-8; return a clear client error instead of sending broken JSON.
            throw new StrandsException(
                'Failed to encode request payload: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        // Middleware runs before auth so payload enrichment cannot invalidate a later SigV4 signature.
        foreach ($this->middleware as $requestMiddleware) {
            // Counted before the call so a middleware that throws here still gets its teardown.
            $enteredMiddlewareCount++;
            $middlewareResult = $requestMiddleware->beforeRequest($url, $headers, $body);
            $headers = $middlewareResult['headers'];
            $body = $middlewareResult['body'];
        }

        // Auth runs last so signatures cover the final headers and body that will actually reach the agent.
        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        return [$headers, $body];
    }

    /**
     * Joins an app-owned route to the endpoint; use it for custom calls, where an empty path targets the base URL.
     *
     * @param string $path request path that becomes part of the signed URL.
     * @return string Full absolute URL for the custom-endpoint call.
     */
    private function buildUrl(string $path): string
    {
        $baseEndpoint = rtrim($this->config->endpoint, '/');
        $normalizedPath = ltrim($path, '/');

        // An empty path means the app wants the agent's base URL itself.
        if ($normalizedPath === '') {
            return $baseEndpoint;
        }

        return $baseEndpoint . '/' . $normalizedPath;
    }

    /**
     * Encodes and authenticates custom JSON; use it for custom calls, where an empty payload remains an empty object.
     *
     * @param string               $url      The full URL.
     * @param array<string, mixed> $payload App payload; an empty map remains an empty JSON object for the custom endpoint.
     * @param string               $accept   The Accept header value.
     * @param int $enteredMiddlewareCount Number of middleware entered; 0 means setup failed before any middleware observed the request.
     *
     * @return array{0: array<string, string>, 1: string} Two-item request tuple; it is never empty and contains final headers plus JSON.
     *
     * @throws StrandsException  If the payload cannot be JSON-encoded.
     */
    private function buildJsonRequest(string $url, array $payload, string $accept, int &$enteredMiddlewareCount): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // For example, an app-owned custom payload may contain invalid UTF-8; fail before auth or transport sees malformed JSON.
            throw new StrandsException(
                'Failed to encode request payload: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        // Middleware runs before auth so body changes cannot invalidate a later SigV4 signature.
        foreach ($this->middleware as $requestMiddleware) {
            // Counted before the call so a middleware that throws here still gets its teardown.
            $enteredMiddlewareCount++;
            $middlewareResult = $requestMiddleware->beforeRequest($url, $headers, $body);
            $headers = $middlewareResult['headers'];
            $body = $middlewareResult['body'];
        }

        // Auth runs last - signatures cover the final request.
        $headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);

        return [$headers, $body];
    }

    /**
     * Decodes one custom SSE frame; use it after framing, while empty, heartbeat, malformed, or non-object data returns null.
     *
     * @param string $rawSseFrame Raw SSE frame; empty means no data and returns null.
     * @return array<string, mixed>|null Decoded object, or null when no safe app event can be produced.
     */
    private static function extractSseData(string $rawSseFrame): ?array
    {
        $eventDataLines = [];

        // Follow the SSE line rules before handing JSON to a custom app callback.
        //
        // - Lines starting with `:` are heartbeat comments and do not reach the callback.
        // - `data:` lines carry payload fragments, joined with `\n` before JSON decoding.
        foreach (explode("\n", $rawSseFrame) as $eventLine) {
            // Lines starting with ":" are heartbeat/comment lines and carry no event data.
            if (str_starts_with($eventLine, ':')) {
                continue;
            }

            // The actual payload rides on "data:" lines (with or without the space).
            if (str_starts_with($eventLine, 'data: ')) {
                $eventDataLines[] = substr($eventLine, 6);
                continue;
            }

            // Some wrappers omit the optional space after `data:`; accept that framing without changing the JSON delivered to the callback.
            if (str_starts_with($eventLine, 'data:')) {
                $eventDataLines[] = substr($eventLine, 5);
            }
        }

        $eventData = implode("\n", $eventDataLines);

        // A comment-only frame (e.g. a keep-alive) carries no data — nothing to emit.
        if ($eventData === '') {
            return null;
        }

        try {
            $decodedEvent = json_decode($eventData, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A custom wrapper can disconnect halfway through JSON; skip that frame so later valid updates can still reach the callback.
            return null;
        }

        // A payload that isn't a JSON object can't be handed to the app callback.
        if (!is_array($decodedEvent)) {
            return null;
        }

        /** @var array<string, mixed> $decodedEvent validated before app code uses it. */
        return $decodedEvent;
    }

    /**
     * Updates safe custom-stream counters; use it before observers, leaving missing or empty control fields unchanged.
     *
     * @param array<string, mixed> $decodedSseEvent Decoded SSE object delivered to the app callback; an empty map has no type and changes nothing.
     * @param int $textEvents running count of text events emitted to the app.
     * @param ?string $terminalType Last complete/error event type seen; null until the stream ends.
     * @param ?Usage $usage Token usage from the terminal event; null until one reports it.
     * @param ?string $stopReason Finish reason from the terminal event; null until one reports it.
     * @return void No returned value; updates the stream summary used by observers.
     */
    private static function updateStreamSseSummary(
        array $decodedSseEvent,
        int &$textEvents,
        ?string &$terminalType,
        ?Usage &$usage,
        ?string &$stopReason,
    ): void {
        $eventType = $decodedSseEvent['type'] ?? null;
        // An event with no string type tells us nothing to summarise; ignore it.
        if (!is_string($eventType)) {
            return;
        }

        // Count text events so telemetry can report how many text updates reached the callback.
        if ($eventType === 'text') {
            $textEvents++;
        }

        // Remember the terminal type so observers know how the stream ended.
        if ($eventType === 'complete' || $eventType === 'error') {
            $terminalType = $eventType;
        }

        $rawUsage = $decodedSseEvent['usage'] ?? null;
        // Capture token usage when the event carries it for the final stream summary.
        if (is_array($rawUsage)) {
            /** @var array<string, mixed> $rawUsage validated before app code uses it. */
            $usage = Usage::fromArray($rawUsage);
        }

        $rawStopReason = $decodedSseEvent['stop_reason'] ?? null;
        // Record why the agent stopped, when the event says so.
        if (is_string($rawStopReason)) {
            $stopReason = $rawStopReason;
        }
    }

    /**
     * Closes request middleware; use it on success or failure, where null error means success and null selection means every middleware.
     *
     * @param string $url agent endpoint the app is calling.
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param float $durationMs elapsed time reported to app telemetry.
     * @param ?\Throwable $error Failure surfaced while awaiting the agent; null when the call succeeded.
     * @param list<RequestMiddleware>|null $middlewareToNotify Middleware to close; null means every configured middleware after a normal operation.
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
        foreach ($middlewareToNotify ?? $this->middleware as $requestMiddleware) {
            try {
                $requestMiddleware->afterResponse($url, $statusCode, $durationMs, $error);
            } catch (\Throwable $exception) {
                // A tracing exporter may fail during teardown; log it without replacing the caller's answer or original error.
                $this->logger->warning('Middleware afterResponse threw an exception', [
                    'middleware' => $requestMiddleware::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Rejects blank sends; use it before invoke() or stream() while allowing attachments and interrupt responses without text.
     *
     * @param string|AgentInput $message user input or rich payload sent to the agent.
     * @return void No value; valid input continues and invalid empty input throws.
     * @throws \InvalidArgumentException  If the message text is empty.
     */
    private static function validateMessage(string|AgentInput $message): void
    {
        $messageText = $message instanceof AgentInput ? $message->getText() : $message;

        // Attachment and interrupt blocks can carry a turn without text; a completely empty turn cannot.
        if ($messageText === '' && !($message instanceof AgentInput && $message->toPayloadValue() !== '')) {
            throw new \InvalidArgumentException(
                'Message cannot be empty. Provide a non-empty string or an AgentInput with content blocks.',
            );
        }
    }

    /**
     * Creates the default transport; use it during construction, preferring Symfony HTTP or returning setup guidance.
     *
     * @return HttpTransport Ready Symfony transport; never null.
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

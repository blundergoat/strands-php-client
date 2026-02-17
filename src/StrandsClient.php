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

    /**
     * @param StrandsConfig       $config     Agent endpoint, auth, timeouts, retry settings.
     * @param HttpTransport|null  $transport  HTTP transport (auto-detected if null).
     * @param LoggerInterface|null $logger    PSR-3 logger (NullLogger if null).
     */
    public function __construct(
        private readonly StrandsConfig $config,
        ?HttpTransport $transport = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->transport = $transport ?? self::detectTransport();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Send a message and wait for the complete response.
     *
     * @param string            $message    The message to send to the agent.
     * @param AgentContext|null  $context    Optional extra context (system prompts, metadata).
     * @param string|null        $sessionId  Optional session ID to continue a conversation.
     *
     * @return AgentResponse
     */
    public function invoke(
        string $message,
        ?AgentContext $context = null,
        ?string $sessionId = null,
    ): AgentResponse {
        $url = rtrim($this->config->endpoint, '/') . '/invoke';
        [$headers, $body] = $this->buildRequest($url, $message, $context, $sessionId, 'application/json');

        $this->logger->debug('Strands invoke request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        $data = $this->postWithRetry($url, $headers, $body);

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
     * @param string            $message    The message to send to the agent.
     * @param callable(StreamEvent): void $onEvent  Called for each event as it arrives.
     * @param AgentContext|null  $context    Optional extra context.
     * @param string|null        $sessionId  Optional session ID.
     *
     * @return StreamResult
     *
     * @throws Exceptions\StreamInterruptedException  If the stream ends without a terminal event.
     */
    public function stream(
        string $message,
        callable $onEvent,
        ?AgentContext $context = null,
        ?string $sessionId = null,
    ): StreamResult {
        $url = rtrim($this->config->endpoint, '/') . '/stream';
        [$headers, $body] = $this->buildRequest($url, $message, $context, $sessionId, 'text/event-stream');

        $this->logger->debug('Strands stream request', [
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        $parser = new StreamParser();
        $receivedTerminal = false;
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

        $this->transport->stream($url, $headers, $body, $this->config->timeout, $this->config->connectTimeout, function (string $chunk) use ($parser, $onEvent, &$receivedTerminal, &$accumulatedText, &$textEvents, &$totalEvents, &$resultSessionId, &$resultUsage, &$resultToolsUsed, &$completeFullText) {
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
                    }
                }

                $onEvent($event);
            }
        });

        if (!$receivedTerminal) {
            throw new Exceptions\StreamInterruptedException(
                'Stream ended without a terminal event (complete or error). The connection may have dropped.',
            );
        }

        $inputTokens = $resultUsage['input_tokens'] ?? 0;
        $outputTokens = $resultUsage['output_tokens'] ?? 0;

        $finalText = $accumulatedText !== '' ? $accumulatedText : ($completeFullText ?? '');

        $result = new StreamResult(
            text: $finalText,
            sessionId: $resultSessionId,
            usage: new Usage(
                inputTokens: is_int($inputTokens) ? $inputTokens : 0,
                outputTokens: is_int($outputTokens) ? $outputTokens : 0,
            ),
            toolsUsed: $resultToolsUsed,
            textEvents: $textEvents,
            totalEvents: $totalEvents,
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
     * Send a POST request with exponential-backoff retry on transient errors.
     *
     * @param string $url
     * @param array<string, string> $headers
     * @param string $body
     *
     * @return array<string, mixed>
     */
    private function postWithRetry(string $url, array $headers, string $body): array
    {
        $attempt = 0;
        $maxRetries = $this->config->maxRetries;

        while (true) {
            try {
                return $this->transport->post($url, $headers, $body, $this->config->timeout, $this->config->connectTimeout);
            } catch (StrandsException $e) {
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
                $baseDelay = $this->config->retryDelayMs * (2 ** min($attempt, 20));
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

        return [$headers, $body];
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

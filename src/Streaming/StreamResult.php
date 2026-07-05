<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\Response\Usage;

/**
 * The accumulated result of a completed stream() call.
 *
 * Aggregates all events into a single object: full text, session ID,
 * token usage, tool history, stop reason, interrupts, guardrail traces,
 * and citations. Also captures client-side metrics like TTFT.
 */
class StreamResult
{
    /**
     * Hold the assembled result of a finished stream() call.
     *
     * Built by the client once the stream ends; the app reads it for the final
     * text, token usage, tools used, and why the agent stopped.
     *
     * @param string          $text                    The full text assembled from all Text events.
     * @param string|null     $sessionId               Session ID from the Complete event.
     * @param Usage           $usage                   Token usage statistics.
     * @param list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}>  $toolsUsed  Tools the agent used.
     * @param int             $textEvents              Number of Text events received.
     * @param int             $totalEvents             Total number of events received.
     * @param StopReason|null $stopReason              Why the agent stopped generating output.
     * @param bool            $cancelled               True if the stream was cancelled by the onEvent callback.
     * @param float|null      $timeToFirstTextTokenMs  Client-measured ms from stream start to first Text event.
     *                                                   Not the same as Usage::$timeToFirstByteMs, which is
     *                                                   server-reported time from request receipt to first byte sent.
     * @param list<InterruptDetail> $interrupts        Interrupts raised by the agent (human-in-the-loop).
     * @param GuardrailTrace|null $guardrailTrace      Guardrail intervention trace data.
     * @param list<array<string, mixed>> $citations    Citation content blocks accumulated during streaming.
     * @param int|null $contextSize                    Current context size in tokens.
     * @param int|null $projectedContextSize           Projected next-turn context size in tokens.
     * @param string|null $terminalType                Terminal stream event type, when observed.
     * @param string|null $errorCode                   Terminal error code, when the stream ended with an error event.
     * @param string|null $errorMessage                Terminal error message, when the stream ended with an error event.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
        public readonly int $textEvents = 0,
        public readonly int $totalEvents = 0,
        public readonly ?StopReason $stopReason = null,
        public readonly bool $cancelled = false,
        public readonly ?float $timeToFirstTextTokenMs = null,
        public readonly array $interrupts = [],
        public readonly ?GuardrailTrace $guardrailTrace = null,
        public readonly array $citations = [],
        public readonly ?int $contextSize = null,
        public readonly ?int $projectedContextSize = null,
        public readonly ?string $terminalType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Whether the agent was interrupted and is waiting for user input.
     *
     * @return bool true when the agent paused and is waiting on the user.
     */
    public function isInterrupted(): bool
    {
        return $this->interrupts !== [];
    }
}

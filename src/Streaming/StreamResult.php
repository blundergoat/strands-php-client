<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\Response\Usage;

/**
 * Holds the final app-facing result assembled after stream() stops.
 *
 * Read it after live callbacks to render final text, continue the session, show usage and tools, handle interrupts, or explain errors and guardrails.
 * It also exposes event counts and client-measured time to first text token for UI and operational diagnostics.
 *
 * Typed stop reasons preserve 1.x switches, while rawStopReason retains future wire values.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") -- this public DTO constructor is append-only throughout 1.x.
 */
class StreamResult
{
    /**
     * Stores the live updates and terminal fields as one result after streaming finishes.
     * StrandsClient builds it automatically; app code reads it for the final screen state and conversation controls.
     *
     * @param string          $text                    Full assembled answer; empty means the stream produced no text.
     * @param string|null     $sessionId               Conversation ID; null means the UI cannot continue this stream as a session.
     * @param Usage           $usage                   Token usage statistics.
     * @param list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}> $toolsUsed
     *        Tools the agent used; empty means no activity to show.
     * @param int             $textEvents              Number of Text events received.
     * @param int             $totalEvents             Total number of events received.
     * @param StopReason|null $stopReason              Known 1.x reason; null means absent or newer, when rawStopReason may still explain it.
     * @param bool            $cancelled               True if the stream was cancelled by the onEvent callback.
     * @param float|null      $timeToFirstTextTokenMs  Client time from stream start to first Text event; null means no text arrived.
     *                                                  This differs from server-reported Usage::$timeToFirstByteMs.
     * @param list<InterruptDetail> $interrupts        User prompts raised by the agent; empty means the UI has nothing to answer.
     * @param GuardrailTrace|null $guardrailTrace      Intervention detail; null means no guardrail trace was returned.
     * @param list<array<string, mixed>> $citations    Raw citation blocks; empty means no sources are available to render.
     * @param int|null $contextSize                    Current context tokens; null means the UI should omit this capacity hint.
     * @param int|null $projectedContextSize           Projected next-turn context tokens; null means the UI should omit this forecast.
     * @param string|null $terminalType                Terminal type; null means no complete/error event was observed; empty is preserved.
     * @param string|null $errorCode                   Terminal error code; null means no code was reported, while an empty string is preserved.
     * @param string|null $errorMessage                Terminal error text; null means no message was reported, while an empty string is preserved.
     * @param string|null $rawStopReason               Exact wire stop reason, including future values; null means the wrapper supplied none.
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
        public readonly ?string $rawStopReason = null,
    ) {
    }

    /**
     * Reports whether the agent paused and needs another user action.
     * Use it to decide whether the UI should render approval or follow-up controls from $interrupts.
     *
     * @return bool true when the agent paused and is waiting on the user.
     */
    public function isInterrupted(): bool
    {
        // An empty interrupt list means the finished stream needs no approval or follow-up input from the user.
        return $this->interrupts !== [];
    }
}

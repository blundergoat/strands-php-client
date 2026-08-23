<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * Routes decoded Strands stream events to typed callback hooks.
 *
 * Extend it when an app needs selected live updates without maintaining its own event switch.
 * Use it with StrandsClient::stream(); raw streamSse() events need a plain callable.
 * Unoverridden hooks leave the app unchanged and return null, allowing the user's stream to finish normally.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- Default hooks retain $event so subclasses can use the typed update.
 */
abstract class StreamCallbackHandler
{
    /**
     * Routes one live agent update to the matching typed hook.
     * StrandsClient calls this so an override can update the app or return false after the user stops streaming.
     *
     * @param StreamEvent $event - Required decoded update from StrandsClient; the event object is never null.
     * @return bool|null - False stops delivery after this event; null keeps the stream active for later updates.
     */
    public function __invoke(StreamEvent $event): ?bool
    {
        // Route each decoded update once so app hooks can focus on visible behavior instead of event-type dispatch.
        return match ($event->type) {
            StreamEventType::Text => $this->onText($event),
            StreamEventType::ToolUse => $this->onToolUse($event),
            StreamEventType::ToolResult => $this->onToolResult($event),
            StreamEventType::Thinking => $this->onThinking($event),
            StreamEventType::Citation => $this->onCitation($event),
            StreamEventType::ReasoningSignature => $this->onReasoningSignature($event),
            StreamEventType::ReasoningRedacted => $this->onReasoningRedacted($event),
            StreamEventType::Complete => $this->onComplete($event),
            StreamEventType::Error => $this->onError($event),
        };
    }

    /**
     * Receives answer text as the agent produces it.
     * Override this hook to append live text or stop updates after the user cancels.
     *
     * @param StreamEvent $event - Required text update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onText(StreamEvent $event): ?bool
    {
        // If the app did not opt into live text, this token leaves the visible answer unchanged and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives notice that the agent is starting a tool.
     * Override this hook to show live tool activity or let the user stop further updates.
     *
     * @param StreamEvent $event - Required tool-use update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onToolUse(StreamEvent $event): ?bool
    {
        // If the app does not show tool activity, its visible progress stays unchanged and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives the result returned by an agent tool.
     * Override this hook to show tool progress or derived details before the final answer arrives.
     *
     * @param StreamEvent $event - Required tool-result update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onToolResult(StreamEvent $event): ?bool
    {
        // Without an override, this tool result adds no live UI detail and the user keeps receiving the response.
        return self::continueStream($event);
    }

    /**
     * Receives live reasoning text from the agent.
     * Override this hook when the app intentionally exposes reasoning or uses it as progress.
     *
     * @param StreamEvent $event - Required reasoning update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onThinking(StreamEvent $event): ?bool
    {
        // Without an override, this reasoning update leaves the app's visible response unchanged and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives a source citation while the response is streaming.
     * Override this hook to add sources to the live answer before the final StreamResult is available.
     *
     * @param StreamEvent $event - Required citation update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onCitation(StreamEvent $event): ?bool
    {
        // If the app waits for the final source list, this citation adds no early UI update and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives a provider reasoning signature during streaming.
     * Override this hook when the app retains provider metadata alongside the live response.
     *
     * @param StreamEvent $event - Required signature update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onReasoningSignature(StreamEvent $event): ?bool
    {
        // If the app does not use reasoning signatures, this metadata leaves its visible response unchanged and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives a marker that provider reasoning was redacted.
     * Override this hook when the app needs to explain why reasoning content is unavailable.
     *
     * @param StreamEvent $event - Required redaction update; absent optional details remain null or empty.
     * @return bool|null - False stops the user's stream; null keeps delivery active without changing the app.
     */
    protected function onReasoningRedacted(StreamEvent $event): ?bool
    {
        // If the app does not show redaction markers, this event leaves its visible response unchanged and streaming continues.
        return self::continueStream($event);
    }

    /**
     * Receives the terminal event for a normally completed stream.
     * Override this hook to end a loading state or add a final UI action before stream() returns.
     *
     * @param StreamEvent $event - Required completion event; absent optional summary details remain null or empty.
     * @return bool|null - False records cancellation; null performs no extra UI work and lets terminal processing finish.
     */
    protected function onComplete(StreamEvent $event): ?bool
    {
        // If the app has no completion hook, no extra UI action runs and stream() finishes normally.
        return self::continueStream($event);
    }

    /**
     * Receives the terminal event for an agent-reported stream error.
     * Override this hook to show live error details before stream() returns its final result.
     *
     * @param StreamEvent $event - Required error event; absent optional error details remain null or empty.
     * @return bool|null - False records cancellation; null performs no extra UI work and lets terminal processing finish.
     */
    protected function onError(StreamEvent $event): ?bool
    {
        // Without an override, this handler adds no UI message and lets the client finish normal terminal error processing.
        return self::continueStream($event);
    }

    /**
     * Returns the non-cancelling result shared by every unoverridden hook.
     * Use this path so subclasses receive typed events while the base handler performs no app-facing work.
     *
     * @param StreamEvent $event - Required unhandled update; absent optional details remain null or empty.
     * @return null - Null performs no UI work and lets StrandsClient continue or finish terminal processing.
     */
    private static function continueStream(StreamEvent $event): null
    {
        return match ($event->type) {
            // Any unoverridden event leaves the app unchanged; null lets normal stream processing continue.
            default => null,
        };
    }
}

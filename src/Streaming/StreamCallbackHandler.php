<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * Abstract callback handler that dispatches stream events to typed methods.
 *
 * Subclasses override only the typed on*() hooks needed to update their live UI; other events are ignored.
 * Use it with stream(); raw streamSse() events require a plain callable instead.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- no-op on*() hooks keep $event so overrides can use it.
 */
abstract class StreamCallbackHandler
{
    /**
     * Dispatch a stream event to the appropriate typed handler.
     *
     * @param StreamEvent $event Decoded event delivered to the app callback.
     * @return bool|null  Return false to cancel the stream; null to continue.
     */
    public function __invoke(StreamEvent $event): ?bool
    {
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
     * Handle a text token event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onText(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a tool-use event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onToolUse(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a tool-result event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onToolResult(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a reasoning text event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onThinking(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a citation event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onCitation(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a reasoning signature event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onReasoningSignature(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a redacted reasoning event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onReasoningRedacted(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a terminal completion event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onComplete(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Handle a terminal error event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onError(StreamEvent $event): ?bool
    {
        return self::continueStream($event);
    }

    /**
     * Keeps default hooks non-cancelling while still accepting the typed event.
     *
     * @param StreamEvent $event event type that reached an unhandled hook.
     * @return null Null tells StrandsClient to keep streaming to the app.
     */
    private static function continueStream(StreamEvent $event): null
    {
        return match ($event->type) {
            default => null,
        };
    }
}

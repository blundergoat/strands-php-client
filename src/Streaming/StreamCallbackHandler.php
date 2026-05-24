<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * Abstract callback handler that dispatches stream events to typed methods.
 *
 * Use as the $onEvent callable for StrandsClient::stream(). Override
 * individual on*() methods to handle specific event types — unhandled
 * events are silently ignored.
 *
 * This handler targets stream() only (typed StreamEvent). For streamSse()
 * (raw array callback), use a plain callable.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
abstract class StreamCallbackHandler
{
    /**
     * Dispatch a stream event to the appropriate typed handler.
     *
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
        return null;
    }

    /**
     * Handle a tool-use event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onToolUse(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a tool-result event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onToolResult(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a reasoning text event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onThinking(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a citation event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onCitation(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a reasoning signature event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onReasoningSignature(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a redacted reasoning event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onReasoningRedacted(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a terminal completion event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onComplete(StreamEvent $event): ?bool
    {
        return null;
    }

    /**
     * Handle a terminal error event when subclasses opt in.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onError(StreamEvent $event): ?bool
    {
        return null;
    }
}

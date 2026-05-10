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

    protected function onText(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onToolUse(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onToolResult(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onThinking(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onCitation(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onReasoningSignature(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onReasoningRedacted(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onComplete(StreamEvent $event): ?bool
    {
        return null;
    }

    protected function onError(StreamEvent $event): ?bool
    {
        return null;
    }
}

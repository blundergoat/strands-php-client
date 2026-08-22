<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Exceptions\StreamInterruptedException;

/**
 * Converts incremental Server-Sent Events bytes into typed updates for a live answer screen.
 *
 * Feed it every transport chunk; it handles split CRLF/LF boundaries, heartbeats, malformed JSON, and unknown future event types.
 * Complete recognized frames become StreamEvent objects, while skipped frames are counted for compatibility diagnostics.
 */
class StreamParser
{
    /** Lazily created transport-chunk decoder, including for 1.x subclasses whose constructor does not call the parent. */
    private ?SseFrameDecoder $frameDecoder = null;

    /** Count of malformed or future events skipped to keep streaming alive. */
    private int $skippedEvents = 0;

    /**
     * Returns how many unusable or future event types were skipped while preserving the live answer.
     * Use it after streaming to log a compatibility hint; zero means every received frame was recognized.
     *
     * @return int Count of skipped stream events.
     */
    public function getSkippedEvents(): int
    {
        return $this->skippedEvents;
    }

    /**
     * Adds one raw transport chunk and returns the complete typed events now ready for the UI.
     * Use it for every streaming callback; an empty or still-partial chunk returns an empty list without losing buffered bytes.
     *
     * @param string $chunk Raw SSE bytes; empty means the transport delivered no progress and produces no events.
     *
     * @return list<StreamEvent> Complete events in arrival order; empty means no user-visible event finished in this chunk.
     * @throws StreamInterruptedException If a broken stream grows beyond the safety limit.
     */
    public function feed(string $chunk): array
    {
        $streamEvents = [];

        // A 1.x consumer subclass may have its own constructor, so create its decoder when the first network chunk arrives.
        $frameDecoder = $this->frameDecoder ??= new SseFrameDecoder();

        // Each complete frame can become one live app update; heartbeats and malformed/future frames are filtered by parseEvent().
        foreach ($frameDecoder->feed($chunk) as $rawEvent) {
            $streamEvent = $this->parseEvent($rawEvent);

            // Only hand real, recognised events to the app; skipped ones come back null.
            if ($streamEvent !== null) {
                $streamEvents[] = $streamEvent;
            }
        }

        return $streamEvents;
    }

    /**
     * Converts one complete SSE frame into the typed update shown by a live app screen.
     * Use it after framing; heartbeat-only, malformed, non-object, and future events return null and update diagnostics as relevant.
     *
     * @param string $rawEvent Raw SSE event block received from the stream.
     * @return StreamEvent|null Parsed event, or null when the block has nothing safe and recognized to show.
     */
    private function parseEvent(string $rawEvent): ?StreamEvent
    {
        $eventDataLines = [];

        // Walk the event's lines, keeping the payload and ignoring SSE bookkeeping.
        foreach (explode("\n", $rawEvent) as $eventLine) {
            // Lines starting with ":" are heartbeat/comment lines — nothing to display.
            if (str_starts_with($eventLine, ':')) {
                continue;
            }

            // The actual payload rides on "data:" lines (with or without the space).
            if (str_starts_with($eventLine, 'data: ')) {
                $eventDataLines[] = substr($eventLine, 6);
                continue;
            }

            // Some wrappers omit the optional space after `data:`; accept that valid SSE form without changing the event content.
            if (str_starts_with($eventLine, 'data:')) {
                $eventDataLines[] = substr($eventLine, 5);
            }
        }

        $eventData = implode("\n", $eventDataLines);

        // A comment-only event (e.g. a keep-alive) carries no data — nothing to emit.
        if ($eventData === '') {
            return null;
        }

        // Skip malformed JSON rather than throwing, which would prevent later valid frames from updating the user's screen.
        try {
            $decodedEvent = json_decode($eventData, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // For example, a proxy can cut one JSON event short; skip it so later complete frames can still update the user's screen.
            $this->skippedEvents++;

            return null;
        }

        // A payload that isn't a typed object can't become an event; skip it and count it.
        if (!is_array($decodedEvent) || !isset($decodedEvent['type'])) {
            $this->skippedEvents++;

            return null;
        }

        // tryFromArray() returns null for future event types, keeping a newer server compatible with this user's current client.
        /** @var array<string, mixed> $decodedEvent validated before app code uses it. */
        $streamEvent = StreamEvent::tryFromArray($decodedEvent);
        // A type this client doesn't know yet (newer server) is skipped, not fatal.
        if ($streamEvent === null) {
            $this->skippedEvents++;

            return null;
        }

        return $streamEvent;
    }
}

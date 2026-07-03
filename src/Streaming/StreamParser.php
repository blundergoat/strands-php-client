<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Exceptions\StreamInterruptedException;

/**
 * Incremental SSE (Server-Sent Events) parser.
 *
 * Buffers raw HTTP response data and emits StreamEvent objects as complete
 * events are detected. Handles chunked delivery, CRLF/LF normalization,
 * and malformed JSON recovery.
 */
class StreamParser
{
    /** Maximum buffer size before throwing (10 MB). */
    private const MAX_BUFFER_SIZE = 10 * 1024 * 1024;

    /** Partial SSE data kept until a full event reaches the app. */
    private string $buffer = '';

    /** Count of malformed or future events skipped to keep streaming alive. */
    private int $skippedEvents = 0;

    /**
     * Return the number of malformed or unknown events skipped by the parser.
     *
     * @return int Count of skipped stream events.
     */
    public function getSkippedEvents(): int
    {
        return $this->skippedEvents;
    }

    /**
     * Feed a raw data chunk and extract any complete events.
     *
     * @param string $chunk  Raw SSE data from the HTTP response.
     *
     * @return StreamEvent[]  Zero or more complete events ready for the app callback.
     * @throws StreamInterruptedException If a broken stream grows beyond the safety limit.
     */
    public function feed(string $chunk): array
    {
        // Guard against unbounded memory growth if the server sends a huge
        // payload without the double-newline event delimiter (e.g. a broken proxy
        // that strips newlines, or a non-SSE response body).
        if (strlen($this->buffer) + strlen($chunk) > self::MAX_BUFFER_SIZE) {
            throw new StreamInterruptedException(
                sprintf('SSE buffer exceeded %d bytes without a complete event', self::MAX_BUFFER_SIZE),
            );
        }

        // Normalise line endings on the new chunk only. The existing buffer is
        // already normalised from a previous feed() call, so re-processing it
        // would be O(buffer_size) wasted work on every chunk.
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);

        $events = [];

        // An SSE event ends at a blank line ("\n\n"); pull out each complete one the
        // buffer holds and leave any half-received trailing event for the next feed().
        while (($position = strpos($this->buffer, "\n\n")) !== false) {
            $rawEvent = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 2);

            $event = $this->parseEvent($rawEvent);

            // Only hand real, recognised events to the app; skipped ones come back null.
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse a single raw SSE event into a StreamEvent.
     *
     * Lines starting with ":" are SSE comments (heartbeats). Lines starting
     * with "data:" contain the JSON payload.
     *
     * @param string $rawEvent Raw SSE event block received from the stream.
     * @return ?StreamEvent The parsed event, or null when the block was a heartbeat or unknown type (skipped).
     */
    private function parseEvent(string $rawEvent): ?StreamEvent
    {
        $dataLines = [];

        // Walk the event's lines, keeping the payload and ignoring SSE bookkeeping.
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

        // A comment-only event (e.g. a keep-alive) carries no data — nothing to emit.
        if ($data === '') {
            return null;
        }

        // Skip malformed JSON rather than throwing - a throw would leave
        // orphaned data in the buffer and cause cascade failures.
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->skippedEvents++;

            return null;
        }

        // A payload that isn't a typed object can't become an event; skip it and count it.
        if (!is_array($decoded) || !isset($decoded['type'])) {
            $this->skippedEvents++;

            return null;
        }

        // tryFromArray() returns null for unknown types rather than throwing,
        // ensuring forward compatibility with new server-side event types.
        /** @var array<string, mixed> $decoded validated before app code uses it. */
        $event = StreamEvent::tryFromArray($decoded);
        // A type this client doesn't know yet (newer server) is skipped, not fatal.
        if ($event === null) {
            $this->skippedEvents++;

            return null;
        }

        return $event;
    }
}

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

    private string $buffer = '';

    private int $skippedEvents = 0;

    public function getSkippedEvents(): int
    {
        return $this->skippedEvents;
    }

    /**
     * Feed a raw data chunk and extract any complete events.
     *
     * @param string $chunk  Raw SSE data from the HTTP response.
     *
     * @return StreamEvent[]  Zero or more complete events.
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

        // Normalise line endings: the SSE spec uses LF, but some proxies/servers
        // may emit CRLF or bare CR. We normalise everything to LF before parsing.
        $this->buffer = str_replace(["\r\n", "\r"], "\n", $this->buffer . $chunk);

        $events = [];

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $rawEvent = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);

            $event = $this->parseEvent($rawEvent);

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
     */
    private function parseEvent(string $rawEvent): ?StreamEvent
    {
        $dataLines = [];

        foreach (explode("\n", $rawEvent) as $line) {
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, 5);
            }
        }

        $data = implode("\n", $dataLines);

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

        if (!is_array($decoded) || !isset($decoded['type'])) {
            $this->skippedEvents++;

            return null;
        }

        // Use tryFrom() to silently skip unknown event types (e.g. new types
        // added server-side that this client version doesn't recognise yet).
        // StreamEvent::fromArray() would throw on unknown types.
        $rawType = $decoded['type'];
        $type = StreamEventType::tryFrom(is_string($rawType) ? $rawType : '');
        if ($type === null) {
            $this->skippedEvents++;

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return StreamEvent::fromArray($decoded);
    }
}

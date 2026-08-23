<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Exceptions\StreamInterruptedException;

/**
 * Converts arbitrary HTTP chunks into complete SSE frames.
 *
 * It normalizes split line endings and limits each unfinished frame to 10 MB while allowing one network chunk to hold many safe frames.
 * The typed `stream()` and raw `streamSse()` paths share this decoder so both APIs apply the same framing and safety behaviour.
 *
 * @internal Shared by the typed and raw streaming entry points.
 */
final class SseFrameDecoder
{
    /** Maximum bytes retained for one unfinished SSE frame (10 MB). */
    private const MAX_FRAME_SIZE = 10 * 1024 * 1024;

    /** Normalized data retained until an SSE blank-line delimiter arrives. */
    private string $buffer = '';

    /** Whether the previous non-empty transport chunk ended midway through CRLF. */
    private bool $isPreviousChunkEndingWithCarriageReturn = false;

    /**
     * Adds one transport chunk and returns every complete raw SSE frame it contains.
     * Use it for each network callback; an empty chunk or one that finishes no frame returns an empty list and keeps any safe partial data buffered.
     *
     * @param string $chunk Raw bytes delivered by the HTTP transport.
     * @return list<string> Complete normalized SSE frames without their blank-line delimiters.
     * @throws StreamInterruptedException If an incomplete frame exceeds the safety limit.
     */
    public function feed(string $chunk): array
    {
        // An empty transport callback cannot finish the buffered frame.
        if ($chunk === '') {
            return [];
        }

        // A trailing CR from the prior chunk plus this leading LF is one line ending, not the blank line that would finish an event.
        if ($this->isPreviousChunkEndingWithCarriageReturn) {
            // For example, a proxy may split `\r\n` across callbacks; discard only the duplicated half created by normalizing the earlier CR.
            if (str_starts_with($chunk, "\n")) {
                $chunk = substr($chunk, 1);
            }
            $this->isPreviousChunkEndingWithCarriageReturn = false;
        }

        // A chunk containing only the LF half of a split CRLF is now empty and cannot advance framing.
        if ($chunk !== '') {
            $this->isPreviousChunkEndingWithCarriageReturn = str_ends_with($chunk, "\r");
            $normalizedChunk = str_replace(["\r\n", "\r"], "\n", $chunk);

            return $this->extractFrames($normalizedChunk);
        }

        return [];
    }

    /**
     * Split normalized bytes at SSE blank lines and retain only the unfinished tail.
     *
     * Use this after each callback so a large chunk containing several bounded events reaches the caller without tripping the per-frame guard.
     *
     * @param string $normalizedChunk Current chunk with every line ending represented by `\n`.
     * @return list<string> Complete normalized SSE frames without their blank-line delimiters; empty when the chunk finished no event.
     * @throws StreamInterruptedException If one unfinished frame exceeds the safety limit before the app can receive it.
     */
    private function extractFrames(string $normalizedChunk): array
    {
        $completeFrames = [];
        $unreadOffset = 0;

        // A blank-line delimiter may straddle callbacks, such as one normalized newline retained from `\r` followed by the next chunk's `\n`.
        if (str_ends_with($this->buffer, "\n") && str_starts_with($normalizedChunk, "\n")) {
            $completeFrames[] = substr($this->buffer, 0, -1);
            $this->buffer = '';
            $unreadOffset = 1;
        }

        // Each blank line completes one SSE frame; enforce the size limit before emitting it.
        while (($frameBoundaryPosition = strpos($normalizedChunk, "\n\n", $unreadOffset)) !== false) {
            $this->appendFrameBytes(substr($normalizedChunk, $unreadOffset, $frameBoundaryPosition - $unreadOffset));
            $completeFrames[] = $this->buffer;
            $this->buffer = '';
            $unreadOffset = $frameBoundaryPosition + 2;
        }

        $this->appendFrameBytes(substr($normalizedChunk, $unreadOffset));

        return $completeFrames;
    }

    /**
     * Append bytes to the current unfinished frame without allowing unbounded memory growth.
     *
     * Use this for both complete-frame fragments and the trailing partial frame, so typed and raw streams enforce one safety rule.
     *
     * @param string $frameBytes Bytes belonging to the current SSE frame; an empty string means a blank event with nothing to retain.
     * @return void The bytes remain buffered until a blank line lets the caller deliver the frame.
     * @throws StreamInterruptedException If this single frame exceeds 10 MB before its terminating blank line arrives.
     */
    private function appendFrameBytes(string $frameBytes): void
    {
        // A wrapper that never terminates an event could otherwise grow the caller's process memory without a bound.
        if (strlen($this->buffer) + strlen($frameBytes) > self::MAX_FRAME_SIZE) {
            throw new StreamInterruptedException(
                sprintf('SSE buffer exceeded %d bytes without a complete event', self::MAX_FRAME_SIZE),
            );
        }

        $this->buffer .= $frameBytes;
    }
}

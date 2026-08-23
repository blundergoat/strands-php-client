<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Verifies the sample printing handler writes answer text, completion spacing, and errors to the intended output stream.
 *
 * Use these tests when changing console streaming output or event-to-output routing.
 * They protect command-line users from mixed diagnostic and answer text.
 */
class PrintingCallbackHandlerTest extends TestCase
{
    /**
     * Confirms text event writes text so apps receive reliable live updates.
     *
     * @return void
     */
    public function testTextEventWritesText(): void
    {
        $output = '';
        $printingCallbackHandler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'Hello world'));

        $this->assertSame('Hello world', $output);
    }

    /**
     * Confirms complete event writes newline so apps receive reliable live updates.
     *
     * @return void
     */
    public function testCompleteEventWritesNewline(): void
    {
        $output = '';
        $printingCallbackHandler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame(PHP_EOL, $output);
    }
    /**
     * Starts with empty error output so the test records only what one failed live update writes.
     * Use it to distinguish diagnostic output from answer text.
     *
     * @return string Empty accumulator before the error callback runs.
     */
    private function emptyErrorOutput(): string
    {
        return '';
    }


    /**
     * Confirms error event writes to stderr so apps receive reliable live updates.
     *
     * @return void
     */
    public function testErrorEventWritesToStderr(): void
    {
        $errorOutput = $this->emptyErrorOutput();
        $printingCallbackHandler = new PrintingCallbackHandler(errorWriter: static function (string $message) use (&$errorOutput): void {
            $errorOutput .= $message;
        });

        $streamEvent = new StreamEvent(
            type: StreamEventType::Error,
            errorCode: 'ERR_001',
            errorMessage: 'Something failed',
        );

        $printingCallbackHandler->__invoke($streamEvent);

        $this->assertSame('Error [ERR_001]: Something failed' . PHP_EOL, $errorOutput);
    }
    /**
     * Starts with empty standard output so hidden stream activity cannot be mistaken for answer text.
     * Use it before dispatching tool, reasoning, citation, or other non-text events.
     *
     * @return string Empty accumulator before the output callback runs.
     */
    private function emptyStandardOutput(): string
    {
        return '';
    }


    /**
     * Confirms non-text events produce no output so the user's live answer contains only visible text.
     *
     * @return void
     */
    public function testNonTextEventsProduceNoOutput(): void
    {
        $output = $this->emptyStandardOutput();
        $printingCallbackHandler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });
        $silentTypes = [
            StreamEventType::ToolUse,
            StreamEventType::ToolResult,
            StreamEventType::Thinking,
            StreamEventType::Citation,
            StreamEventType::ReasoningSignature,
            StreamEventType::ReasoningRedacted,
        ];

        // Non-text activity may update another widget, but it must not print into the user's answer text.
        foreach ($silentTypes as $silentEventType) {
            $printingCallbackHandler->__invoke(new StreamEvent(type: $silentEventType));

            $this->assertSame('', $output, "Unexpected output for {$silentEventType->value}");
        }
    }

    /**
     * Confirms multiple text events concatenate so apps receive reliable live updates.
     *
     * @return void
     */
    public function testMultipleTextEventsConcatenate(): void
    {
        $output = '';
        $printingCallbackHandler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'Hello'));
        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Text, text: ' '));
        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'world'));
        $printingCallbackHandler->__invoke(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame('Hello world' . PHP_EOL, $output);
    }
}

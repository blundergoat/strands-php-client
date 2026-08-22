<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Printing Callback Handler behavior for app integrations.
 *
 * Use this file when changing Printing Callback Handler or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Exercises Printing Callback Handler through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class PrintingCallbackHandlerTest extends TestCase
{
    /**
     * Confirms text event writes text so live answer updates and completion state stay reliable.
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
     * Confirms complete event writes newline so live answer updates and completion state stay reliable.
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
     * Test fixture for testErrorEventWritesToStderr().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForErrorEventWritesToStderr(): string
    {
        return '';
    }


    /**
     * Confirms error event writes to stderr so live answer updates and completion state stay reliable.
     *
     * @return void
     */
    public function testErrorEventWritesToStderr(): void
    {
        $errorOutput = $this->rawForErrorEventWritesToStderr();
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
     * Test fixture for testNonTextEventsProduceNoOutput().
     *
     * @return string text value used in the caller-facing agent flow.
     */
    private function rawForNonTextEventsProduceNoOutput(): string
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
        $output = $this->rawForNonTextEventsProduceNoOutput();
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
     * Confirms multiple text events concatenate so live answer updates and completion state stay reliable.
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

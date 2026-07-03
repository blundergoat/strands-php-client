<?php

declare(strict_types=1);

/**
 * Tests caller-visible Printing Callback Handler behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Verifies Printing Callback Handler behavior that application users rely on.
 */
class PrintingCallbackHandlerTest extends TestCase
{
    /**
     * Verifies that text event writes text.
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
     * Verifies that complete event writes newline.
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
     * Verifies that error event writes to stderr.
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
     * Verifies that nonText events produce no output.
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

        foreach ($silentTypes as $type) {
            $printingCallbackHandler->__invoke(new StreamEvent(type: $type));

            $this->assertSame('', $output, "Unexpected output for {$type->value}");
        }
    }

    /**
     * Verifies that multiple text events concatenate.
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

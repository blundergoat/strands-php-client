<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

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

        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Text, text: 'Hello world'));

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

        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame(PHP_EOL, $output);
    }

    /**
     * Verifies that error event writes to stderr.
     *
     * @return void
     */
    public function testErrorEventWritesToStderr(): void
    {
        $errorOutput = '';
        $printingCallbackHandler = new PrintingCallbackHandler(errorWriter: static function (string $message) use (&$errorOutput): void {
            $errorOutput .= $message;
        });

        $streamEvent = new StreamEvent(
            type: StreamEventType::Error,
            errorCode: 'ERR_001',
            errorMessage: 'Something failed',
        );

        $printingCallbackHandler($streamEvent);

        $this->assertSame('Error [ERR_001]: Something failed' . PHP_EOL, $errorOutput);
    }

    /**
     * Verifies that nonText events produce no output.
     *
     * @return void
     */
    public function testNonTextEventsProduceNoOutput(): void
    {
        $output = '';
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
            $printingCallbackHandler(new StreamEvent(type: $type));

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

        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Text, text: 'Hello'));
        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Text, text: ' '));
        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Text, text: 'world'));
        $printingCallbackHandler(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame('Hello world' . PHP_EOL, $output);
    }
}

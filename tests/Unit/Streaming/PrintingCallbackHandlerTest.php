<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class PrintingCallbackHandlerTest extends TestCase
{
    public function testTextEventWritesText(): void
    {
        $output = '';
        $handler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $handler(new StreamEvent(type: StreamEventType::Text, text: 'Hello world'));

        $this->assertSame('Hello world', $output);
    }

    public function testCompleteEventWritesNewline(): void
    {
        $output = '';
        $handler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $handler(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame(PHP_EOL, $output);
    }

    public function testErrorEventWritesToStderr(): void
    {
        $errorOutput = '';
        $handler = new PrintingCallbackHandler(errorWriter: static function (string $message) use (&$errorOutput): void {
            $errorOutput .= $message;
        });

        $event = new StreamEvent(
            type: StreamEventType::Error,
            errorCode: 'ERR_001',
            errorMessage: 'Something failed',
        );

        $handler($event);

        $this->assertSame('Error [ERR_001]: Something failed' . PHP_EOL, $errorOutput);
    }

    public function testNonTextEventsProduceNoOutput(): void
    {
        $output = '';
        $handler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
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
            $handler(new StreamEvent(type: $type));

            $this->assertSame('', $output, "Unexpected output for {$type->value}");
        }
    }

    public function testMultipleTextEventsConcatenate(): void
    {
        $output = '';
        $handler = new PrintingCallbackHandler(outputWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $handler(new StreamEvent(type: StreamEventType::Text, text: 'Hello'));
        $handler(new StreamEvent(type: StreamEventType::Text, text: ' '));
        $handler(new StreamEvent(type: StreamEventType::Text, text: 'world'));
        $handler(new StreamEvent(type: StreamEventType::Complete));

        $this->assertSame('Hello world' . PHP_EOL, $output);
    }
}

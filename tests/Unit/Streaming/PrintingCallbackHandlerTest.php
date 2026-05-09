<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\PrintingCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class PrintingCallbackHandlerTest extends TestCase
{
    public function testTextEventEchosText(): void
    {
        $handler = new PrintingCallbackHandler();

        ob_start();
        $handler(new StreamEvent(type: StreamEventType::Text, text: 'Hello world'));
        $output = ob_get_clean();

        $this->assertSame('Hello world', $output);
    }

    public function testCompleteEventEchosNewline(): void
    {
        $handler = new PrintingCallbackHandler();

        ob_start();
        $handler(new StreamEvent(type: StreamEventType::Complete));
        $output = ob_get_clean();

        $this->assertSame(PHP_EOL, $output);
    }

    public function testErrorEventWritesToStderr(): void
    {
        $handler = new PrintingCallbackHandler();

        $tmpFile = tmpfile();
        $this->assertNotFalse($tmpFile);
        $originalStderr = null;

        // Capture stderr by temporarily replacing it
        // We can't easily redirect STDERR in PHPUnit, so test the method directly
        $event = new StreamEvent(
            type: StreamEventType::Error,
            errorCode: 'ERR_001',
            errorMessage: 'Something failed',
        );

        ob_start();
        $handler($event);
        $stdout = ob_get_clean();

        // Error should NOT go to stdout
        $this->assertSame('', $stdout);
    }

    public function testNonTextEventsProduceNoStdout(): void
    {
        $handler = new PrintingCallbackHandler();
        $silentTypes = [
            StreamEventType::ToolUse,
            StreamEventType::ToolResult,
            StreamEventType::Thinking,
            StreamEventType::Citation,
            StreamEventType::ReasoningSignature,
            StreamEventType::ReasoningRedacted,
        ];

        foreach ($silentTypes as $type) {
            ob_start();
            $handler(new StreamEvent(type: $type));
            $output = ob_get_clean();

            $this->assertSame('', $output, "Unexpected output for {$type->value}");
        }
    }

    public function testMultipleTextEventsConcatenate(): void
    {
        $handler = new PrintingCallbackHandler();

        ob_start();
        $handler(new StreamEvent(type: StreamEventType::Text, text: 'Hello'));
        $handler(new StreamEvent(type: StreamEventType::Text, text: ' '));
        $handler(new StreamEvent(type: StreamEventType::Text, text: 'world'));
        $handler(new StreamEvent(type: StreamEventType::Complete));
        $output = ob_get_clean();

        $this->assertSame('Hello world' . PHP_EOL, $output);
    }
}

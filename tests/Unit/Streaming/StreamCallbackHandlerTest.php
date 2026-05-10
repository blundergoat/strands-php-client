<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class StreamCallbackHandlerTest extends TestCase
{
    public function testTextEventDispatchesToOnText(): void
    {
        $received = null;
        $handler = new class () extends StreamCallbackHandler {
            /** @var StreamEvent|null */
            public ?StreamEvent $received = null;

            protected function onText(StreamEvent $event): ?bool
            {
                $this->received = $event;

                return null;
            }
        };

        $event = new StreamEvent(type: StreamEventType::Text, text: 'hello');
        $handler($event);

        $this->assertSame($event, $handler->received);
    }

    public function testToolUseEventDispatchesToOnToolUse(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            protected function onToolUse(StreamEvent $event): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler(new StreamEvent(type: StreamEventType::ToolUse, toolName: 'search'));

        $this->assertTrue($handler->called);
    }

    public function testCompleteEventDispatchesToOnComplete(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            protected function onComplete(StreamEvent $event): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler(new StreamEvent(type: StreamEventType::Complete));

        $this->assertTrue($handler->called);
    }

    public function testErrorEventDispatchesToOnError(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            protected function onError(StreamEvent $event): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler(new StreamEvent(type: StreamEventType::Error, errorCode: 'ERR', errorMessage: 'fail'));

        $this->assertTrue($handler->called);
    }

    public function testHandlerIsCallable(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $this->assertTrue(is_callable($handler));
    }

    public function testHandlerReturnsNullByDefault(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $result = $handler(new StreamEvent(type: StreamEventType::Text, text: 'hi'));

        $this->assertNull($result);
    }

    public function testConcreteSubclassCanOverrideSpecificMethods(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            public array $log = [];

            protected function onText(StreamEvent $event): ?bool
            {
                $this->log[] = 'text:' . $event->text;

                return null;
            }

            protected function onToolUse(StreamEvent $event): ?bool
            {
                $this->log[] = 'tool:' . $event->toolName;

                return null;
            }
        };

        $handler(new StreamEvent(type: StreamEventType::Text, text: 'hello'));
        $handler(new StreamEvent(type: StreamEventType::ToolUse, toolName: 'search'));
        $handler(new StreamEvent(type: StreamEventType::Thinking, text: 'thinking'));

        $this->assertSame(['text:hello', 'tool:search'], $handler->log);
    }

    public function testAllEventTypesDispatchWithoutError(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        foreach (StreamEventType::cases() as $type) {
            $result = $handler(new StreamEvent(type: $type));
            $this->assertNull($result, "Handler returned non-null for {$type->value}");
        }
    }

    public function testTypedHandlerCanCancelStream(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            protected function onText(StreamEvent $event): ?bool
            {
                return false;
            }
        };

        $result = $handler(new StreamEvent(type: StreamEventType::Text, text: 'stop'));

        $this->assertFalse($result);
    }
}

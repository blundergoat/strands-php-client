<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

class StreamCallbackHandlerTest extends TestCase
{
    /**
     * Verifies that text event dispatches to onText.
     *
     * @return void
     */
    public function testTextEventDispatchesToOnText(): void
    {
        $received = null;
        $handler = new class () extends StreamCallbackHandler {
            /** @var StreamEvent|null */
            public ?StreamEvent $received = null;

            /**
             * Handle a text event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                $this->received = $streamEvent;

                return null;
            }
        };

        $streamEvent = new StreamEvent(type: StreamEventType::Text, text: 'hello');
        $handler->__invoke($streamEvent);

        $this->assertSame($streamEvent, $handler->received);
    }

    /**
     * Verifies that tool use event dispatches to onToolUse.
     *
     * @return void
     */
    public function testToolUseEventDispatchesToOnToolUse(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            /**
             * Handle a tool-use event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onToolUse(StreamEvent $streamEvent): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler->__invoke(new StreamEvent(type: StreamEventType::ToolUse, toolName: 'search'));

        $this->assertTrue($handler->called);
    }

    /**
     * Verifies that complete event dispatches to onComplete.
     *
     * @return void
     */
    public function testCompleteEventDispatchesToOnComplete(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            /**
             * Handle a completion event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onComplete(StreamEvent $streamEvent): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler->__invoke(new StreamEvent(type: StreamEventType::Complete));

        $this->assertTrue($handler->called);
    }

    /**
     * Verifies that error event dispatches to onError.
     *
     * @return void
     */
    public function testErrorEventDispatchesToOnError(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            public bool $called = false;

            /**
             * Handle an error event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onError(StreamEvent $streamEvent): ?bool
            {
                $this->called = true;

                return null;
            }
        };

        $handler->__invoke(new StreamEvent(type: StreamEventType::Error, errorCode: 'ERR', errorMessage: 'fail'));

        $this->assertTrue($handler->called);
    }

    /**
     * Verifies that handler is callable.
     *
     * @return void
     */
    public function testHandlerIsCallable(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $this->assertTrue(is_callable($handler));
    }

    /**
     * Verifies that handler returns null by default.
     *
     * @return void
     */
    public function testHandlerReturnsNullByDefault(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $result = $handler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'hi'));

        $this->assertNull($result);
    }

    /**
     * Verifies that concrete subclass can override specific methods.
     *
     * @return void
     */
    public function testConcreteSubclassCanOverrideSpecificMethods(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            public array $log = [];

            /**
             * Handle a text event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                $this->log[] = 'text:' . $streamEvent->text;

                return null;
            }

            /**
             * Handle a tool-use event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onToolUse(StreamEvent $streamEvent): ?bool
            {
                $this->log[] = 'tool:' . $streamEvent->toolName;

                return null;
            }
        };

        $handler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'hello'));
        $handler->__invoke(new StreamEvent(type: StreamEventType::ToolUse, toolName: 'search'));
        $handler->__invoke(new StreamEvent(type: StreamEventType::Thinking, text: 'thinking'));

        $this->assertSame(['text:hello', 'tool:search'], $handler->log);
    }

    /**
     * Verifies that all event types dispatch without error.
     *
     * @return void
     */
    public function testAllEventTypesDispatchWithoutError(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        foreach (StreamEventType::cases() as $type) {
            $result = $handler->__invoke(new StreamEvent(type: $type));
            $this->assertNull($result, "Handler returned non-null for {$type->value}");
        }
    }

    /**
     * Verifies that typed handler can cancel stream.
     *
     * @return void
     */
    public function testTypedHandlerCanCancelStream(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /**
             * Handle a text event in the anonymous test handler.
             *
             * @param StreamEvent $streamEvent Stream event being handled.
             * @return bool|null False cancels the stream; null continues it.
             */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                return false;
            }
        };

        $result = $handler->__invoke(new StreamEvent(type: StreamEventType::Text, text: 'stop'));

        $this->assertFalse($result);
    }
}

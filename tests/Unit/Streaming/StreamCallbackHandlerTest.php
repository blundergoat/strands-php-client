<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * Verifies that StreamCallbackHandler's __invoke dispatches each event type
     * to the matching protected `on*` hook. The anonymous subclass below
     * records the hook name(s) that fired so each row of the data provider
     * can assert the dispatch landed on exactly one expected method.
     *
     * @param StreamEvent $streamEvent Event sent through the handler under test.
     * @param string $expectedHook Name of the hook expected to record the event.
     * @return void
     */
    #[DataProvider('dispatchProvider')]
    public function testEventDispatchesToMatchingHook(StreamEvent $streamEvent, string $expectedHook): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            public array $calls = [];

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onText';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onToolUse(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onToolUse';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onToolResult(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onToolResult';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onThinking(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onThinking';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onCitation(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onCitation';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onReasoningSignature(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onReasoningSignature';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onReasoningRedacted(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onReasoningRedacted';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onComplete(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onComplete';

                return null;
            }

            /** @param StreamEvent $streamEvent Stream event being handled. */
            protected function onError(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onError';

                return null;
            }
        };

        $handler->__invoke($streamEvent);

        $this->assertSame([$expectedHook], $handler->calls);
    }

    /**
     * Cases for testEventDispatchesToMatchingHook().
     *
     * @return iterable<string, array{0: StreamEvent, 1: string}>
     */
    public static function dispatchProvider(): iterable
    {
        yield 'ToolUse → onToolUse' => [
            new StreamEvent(type: StreamEventType::ToolUse, toolName: 'search'),
            'onToolUse',
        ];
        yield 'Complete → onComplete' => [
            new StreamEvent(type: StreamEventType::Complete),
            'onComplete',
        ];
        yield 'Error → onError' => [
            new StreamEvent(type: StreamEventType::Error, errorCode: 'ERR', errorMessage: 'fail'),
            'onError',
        ];
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

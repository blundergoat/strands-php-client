<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Stream Callback Handler behavior for app integrations.
 *
 * Use this file when changing Stream Callback Handler or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Exercises Stream Callback Handler through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class StreamCallbackHandlerTest extends TestCase
{
    /**
     * Verifies that text event dispatches to onText so the user's live answer and completion state stay reliable.
     *
     * @return void
     */
    public function testTextEventDispatchesToOnText(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            private ?StreamEvent $received = null;

            /**
             * Return the event captured for the app text callback.
             *
             * @return StreamEvent|null captured event, or null before dispatch.
             */
            public function received(): ?StreamEvent
            {
                return $this->received;
            }

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

        $this->assertSame($streamEvent, $handler->received());
    }

    /**
     * Verify __invoke() dispatches each stream event to one matching protected hook.
     *
     * The anonymous handler records the hook reached by each provider row.
     * This protects the live UI update expected for every event type.
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
            private array $calls = [];

            /**
             * Return the hooks reached by the app stream event.
             *
             * @return list<string> hook names recorded during dispatch.
             */
            public function calls(): array
            {
                return $this->calls;
            }

            /**
             * Records text dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onText';

                return null;
            }

            /**
             * Records tool-use dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onToolUse(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onToolUse';

                return null;
            }

            /**
             * Records tool-result dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onToolResult(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onToolResult';

                return null;
            }

            /**
             * Records thinking dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onThinking(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onThinking';

                return null;
            }

            /**
             * Records citation dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onCitation(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onCitation';

                return null;
            }

            /**
             * Records reasoning-signature dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onReasoningSignature(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onReasoningSignature';

                return null;
            }

            /**
             * Records redacted-reasoning dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onReasoningRedacted(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onReasoningRedacted';

                return null;
            }

            /**
             * Records completion dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onComplete(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onComplete';

                return null;
            }

            /**
             * Records error dispatch for the streaming UI callback path.
             *
             * @param StreamEvent $streamEvent Event being dispatched.
             * @return ?bool Null keeps the test stream running.
             */
            protected function onError(StreamEvent $streamEvent): ?bool
            {
                $this->calls[] = 'onError';

                return null;
            }
        };

        $handler->__invoke($streamEvent);

        $this->assertSame([$expectedHook], $handler->calls());
    }

    /**
     * Cases for testEventDispatchesToMatchingHook().
     *
     * @return iterable<string, array{0: StreamEvent, 1: string}> Streaming callback scenarios that map events to app updates.
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
     * Confirms handler is callable so live answer updates and completion state stay reliable.
     *
     * @return void
     */
    public function testHandlerIsCallable(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $this->assertTrue(is_callable($handler));
    }

    /**
     * Confirms handler returns null by default so live answer updates and completion state stay reliable.
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
     * Confirms concrete subclass can override specific methods so live answer updates and completion state stay reliable.
     *
     * @return void
     */
    public function testConcreteSubclassCanOverrideSpecificMethods(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            private array $log = [];

            /**
             * Return callback output collected by the concrete test handler.
             *
             * @return list<string> entries produced by stream hooks.
             */
            public function log(): array
            {
                return $this->log;
            }

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

        $this->assertSame(['text:hello', 'tool:search'], $handler->log());
    }

    /**
     * Confirms all event types dispatch without error so live answer updates and completion state stay reliable.
     *
     * @return void
     */
    public function testAllEventTypesDispatchWithoutError(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        // Every current event type must reach the default handler without cancelling the user's stream.
        foreach (StreamEventType::cases() as $streamEventType) {
            $result = $handler->__invoke(new StreamEvent(type: $streamEventType));
            $this->assertNull($result, "Handler returned non-null for {$streamEventType->value}");
        }
    }

    /**
     * Confirms typed handler can cancel stream so live answer updates and completion state stay reliable.
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

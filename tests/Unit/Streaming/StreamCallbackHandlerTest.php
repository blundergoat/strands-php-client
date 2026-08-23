<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Streaming\StreamCallbackHandler;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Verifies every typed stream event reaches its matching callback hook and preserves cancellation results.
 *
 * Use these tests when adding event types or changing callback dispatch and subclass overrides.
 * They protect live application updates from being routed to the wrong handler or cancelled accidentally.
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
             * Returns the event received by the simulated live-text hook.
             *
             * @return StreamEvent|null Captured event, or null before the simulated update is dispatched.
             */
            public function received(): ?StreamEvent
            {
                return $this->received;
            }

            /**
             * Records a text event so the test can prove the live-answer hook ran.
             *
             * @param StreamEvent $streamEvent Text update delivered to the application hook.
             * @return bool|null Null keeps the simulated stream running; this hook never cancels it.
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
     * @param string $expectedHook Non-empty hook name expected to record the event.
     * @return void
     */
    #[DataProvider('dispatchProvider')]
    public function testEventDispatchesToMatchingHook(StreamEvent $streamEvent, string $expectedHook): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            private array $calls = [];

            /**
             * Returns the hooks reached by the app stream event.
             *
             * @return list<string> Hook names recorded during dispatch; empty before the first event.
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
     * Lists event types and the application hook each one must reach.
     *
     * @return iterable<string, array{0: StreamEvent, 1: string}> Non-empty event-to-callback cases for live app updates.
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
     * Confirms handler is callable so apps receive reliable live updates.
     *
     * @return void
     */
    public function testHandlerIsCallable(): void
    {
        $handler = new class () extends StreamCallbackHandler {};

        $this->assertTrue(is_callable($handler));
    }

    /**
     * Confirms handler returns null by default so apps receive reliable live updates.
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
     * Confirms concrete subclass can override specific methods so apps receive reliable live updates.
     *
     * @return void
     */
    public function testConcreteSubclassCanOverrideSpecificMethods(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /** @var list<string> */
            private array $log = [];

            /**
             * Returns callback output collected by the concrete test handler.
             *
             * @return list<string> Entries produced by stream hooks; empty before an event is dispatched.
             */
            public function log(): array
            {
                return $this->log;
            }

            /**
             * Records text updates the application would append to its live answer.
             *
             * @param StreamEvent $streamEvent Text update delivered to the application hook.
             * @return bool|null Null keeps the simulated stream running; this hook never cancels it.
             */
            protected function onText(StreamEvent $streamEvent): ?bool
            {
                $this->log[] = 'text:' . $streamEvent->text;

                return null;
            }

            /**
             * Records tool-use updates the application could show in its activity view.
             *
             * @param StreamEvent $streamEvent Tool-use update delivered to the application hook.
             * @return bool|null Null keeps the simulated stream running; this hook never cancels it.
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
     * Confirms all event types dispatch without error so apps receive reliable live updates.
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
     * Confirms typed handler can cancel stream so apps receive reliable live updates.
     *
     * @return void
     */
    public function testTypedHandlerCanCancelStream(): void
    {
        $handler = new class () extends StreamCallbackHandler {
            /**
             * Cancels when text arrives, matching an app that stops after its first update.
             *
             * @param StreamEvent $streamEvent Text update that triggers caller-requested cancellation.
             * @return bool|null False stops the stream; this hook never continues it with null.
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

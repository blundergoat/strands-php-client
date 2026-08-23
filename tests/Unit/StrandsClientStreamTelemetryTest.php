<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamResult;

/**
 * Verifies typed streams expose safe debug context and caller-measured timing metadata.
 *
 * Use these tests when changing streaming logs, event counts, or time-to-first-text measurement.
 * They protect operator diagnostics without leaking the user's prompt or generated answer.
 */
class StrandsClientStreamTelemetryTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic typed-streaming scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $fixtureName Non-empty SSE fixture filename under tests/Fixtures/.
     * @return string Captured SSE bytes; empty means no event reaches the app callback.
     */
    private function loadSseFixture(string $fixtureName): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/' . $fixtureName);
    }

    /**
     * Builds a log callback that asserts the fields operators rely on during streaming.
     * Use it to keep request and completion diagnostics consistent across scenarios.
     *
     * @param array<string, list<string>> $expectedKeysPerMessage Non-empty map of log messages to required context keys.
     * @return \Closure(string, array<string, mixed>=): void Non-null assertion callback; its log context may be empty.
     */
    private function assertRequiredDebugContextKeys(array $expectedKeysPerMessage): \Closure
    {
        return function (string $message, array $context = []) use ($expectedKeysPerMessage): void {
            $required = $expectedKeysPerMessage[$message] ?? null;
            // Ignore unrelated debug messages; each test lists only the caller-visible operation logs it owns.
            if ($required === null) {
                return;
            }
            // Every required key keeps an existing log consumer working after the 1.5 additions.
            foreach ($required as $key) {
                \PHPUnit\Framework\Assert::assertArrayHasKey($key, $context, "Log '{$message}' must include context key '{$key}'");
            }
        };
    }

    /**
     * Lists the stable debug fields emitted when a stream starts and completes.
     * Use it when several tests enforce the same operator-facing diagnostic shape.
     *
     * @return array<string, list<string>> Non-empty required-key map keyed by log message.
     */
    private function requiredStreamDebugContextKeys(): array
    {
        return [
            'Strands stream request' => ['url', 'session_id'],
            'Strands stream complete' => [
                'session_id',
                'text_events',
                'total_events',
                'text_length',
                'input_tokens',
                'output_tokens',
                'ttft_ms',
                'tools_used',
                'cancelled',
            ],
        ];
    }

    /**
     * Creates a controlled HTTP transport that reproduces the response chunks an app could receive.
     * Use it when the typed-streaming scenario must inspect requests or delivery order.
     *
     * @param string $sseFixture SSE bytes yielded by the mock; empty means the app receives no events.
     * @return HttpTransport Mock transport that delivers the supplied SSE fixture; never null.
     */
    private function createStreamingTransport(string $sseFixture): HttpTransport
    {
        $mock = $this->createMock(HttpTransport::class);
        $mock->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseFixture) {
                $onChunk->__invoke($sseFixture);
            });

        return $mock;
    }

    /**
     * Verifies stream() logs debug messages so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamLogsDebugMessages(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback($this->assertRequiredDebugContextKeys($this->requiredStreamDebugContextKeys()));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies stream() leaves time to first text null until a text event arrives.
     * This keeps live updates consistent with the final result returned to the caller.
     *
     * @return void
     */
    public function testStreamResultDefaultsTimeToFirstTextTokenToNull(): void
    {
        $streamResult = new StreamResult(text: '');

        $this->assertNull($streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Verifies stream() records time to first text when the first text event arrives.
     * This keeps live updates consistent with the final result returned to the caller.
     *
     * @return void
     */
    public function testStreamRecordsTtftWhenTextEventsPresent(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        // Receiving text gives the final result a nonnegative client-measured time-to-first-text value.
        $this->assertNotNull($streamResult->timeToFirstTextTokenMs);
        $this->assertIsFloat($streamResult->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0.0, $streamResult->timeToFirstTextTokenMs);
    }

    /**
     * Verifies stream() logs skipped events so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamLogsSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-with-unknown-event.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // An unknown server event produces an upgrade hint for operators without interrupting the user's answer.
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'strands.stream.skipped_events',
                $this->callback(function (array $context): bool {
                    return $context['count'] === 1
                        && isset($context['hint']);
                }),
            );

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() does not log when no skipped events so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamDoesNotLogWhenNoSkippedEvents(): void
    {
        $sseData = $this->loadSseFixture('sse-simple-text.txt');
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);

        // A fully recognized stream produces no compatibility warning for operators.
        $logger->expects($this->never())
            ->method('info');

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertInstanceOf(StreamResult::class, $streamResult);
    }

    /**
     * Verifies stream() debug log includes token timing field so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamDebugLogIncludesTokenTimingField(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $debugCalls = [];
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function (string $message, array $context) use (&$debugCalls): void {
                $debugCalls[] = ['message' => $message, 'context' => $context];
            });

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertSame('Strands stream complete', $debugCalls[1]['message']);
        $this->assertArrayHasKey('ttft_ms', $debugCalls[1]['context']);
        $this->assertNotNull($debugCalls[1]['context']['ttft_ms']);
    }

    /**
     * Verifies stream() logs debug on request and completion so live callbacks and the final result agree.
     *
     * @return void
     */
    public function testStreamLogsDebugOnRequestAndCompletion(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . 'data: {"type": "complete", "text": "Hello", "session_id": "s1", '
            . '"usage": {"inputTokens": 10, "outputTokens": 5}, "tools_used": []}' . "\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback($this->assertRequiredDebugContextKeys($this->requiredStreamDebugContextKeys()));

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
            logger: $logger,
        );

        $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );
    }

    /**
     * Verifies stream() records a positive time to first text after a text event.
     * This keeps live updates consistent with the final result returned to the caller.
     *
     * @return void
     */
    public function testStreamTtftIsPositiveWhenTextEventsExist(): void
    {
        $sseData = "data: {\"type\": \"text\", \"content\": \"Hello\"}\n\n"
            . "data: {\"type\": \"complete\", \"text\": \"Hello\", \"session_id\": null, \"usage\": {}, \"tools_used\": []}\n\n";
        $transport = $this->createStreamingTransport($sseData);

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://localhost:8081'),
            transport: $transport,
        );

        $streamResult = $strandsClient->stream(
            message: 'Test',
            onEvent: function (): void {
            },
        );

        $this->assertNotNull($streamResult->timeToFirstTextTokenMs);
        $this->assertGreaterThanOrEqual(0, $streamResult->timeToFirstTextTokenMs);
    }
}

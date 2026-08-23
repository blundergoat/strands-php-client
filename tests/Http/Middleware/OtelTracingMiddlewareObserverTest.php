<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\Response\Usage;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Verifies parsed invoke and stream results finish active spans with safe, caller-relevant attributes.
 *
 * Use these tests when changing response observers or terminal error handling.
 * They protect operator telemetry without recording prompts, answers, or session identifiers.
 */
class OtelTracingMiddlewareObserverTest extends TestCase
{
    /** Captures spans so tests can inspect what app telemetry would receive. */
    private InMemoryExporter $exporter;

    /** Owns the test tracer lifecycle for each middleware scenario. */
    private TracerProvider $tracerProvider;

    /** Middleware under test for agent request tracing. */
    private OtelTracingMiddleware $middleware;

    /**
     * Starts isolated test state before a user-request scenario, preventing spans or callbacks from leaking between tests.
     * Use it automatically before telemetry checks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $tracer = $this->tracerProvider->getTracer('test');
        $this->middleware = OtelTracingMiddleware::create($tracer);
    }

    /**
     * Clears shared test state after a user-request scenario so the next test represents a fresh app session.
     * Use it automatically after telemetry checks.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    /**
     * Returns spans exported after the simulated caller operation finishes.
     * Use it when assertions need the telemetry an operator backend would receive.
     *
     * @return list<ImmutableSpan> Exported spans; empty means no operation produced telemetry.
     */
    private function exportedSpans(): array
    {
        $this->tracerProvider->forceFlush();

        return $this->exporter->getSpans();
    }

    /**
     * Verifies the response observer adds invoke attributes before its span ends so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testResponseObserverAddsInvokeAttributesBeforeSpanEnds(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/invoke', [], '{}');
        $this->middleware->afterInvoke('https://agent.example.com/invoke', AgentResponse::fromArray([
            'text' => 'Done',
            'agent' => 'booking-assistant',
            'session_id' => 'session-secret',
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 4,
                'cache_read_input_tokens' => 2,
                'cache_write_input_tokens' => 1,
            ],
            'tools_used' => [
                ['name' => 'availability_lookup'],
                ['name' => 'availability_lookup'],
                ['name' => 'booking_confirmation'],
            ],
            'stop_reason' => 'end_turn',
            'model' => 'claude-test',
        ]), 40.0);
        $this->middleware->afterResponse('https://agent.example.com/invoke', 200, 40.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame('booking-assistant', $span->getAttributes()->get('strands.agent.name'));
        $this->assertTrue($span->getAttributes()->get('strands.session.present'));
        $this->assertSame(12, $span->getAttributes()->get('gen_ai.usage.input_tokens'));
        $this->assertSame(4, $span->getAttributes()->get('gen_ai.usage.output_tokens'));
        $this->assertSame(2, $span->getAttributes()->get('gen_ai.usage.cache_read_input_tokens'));
        $this->assertSame(1, $span->getAttributes()->get('gen_ai.usage.cache_write_input_tokens'));
        $this->assertSame('end_turn', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame('claude-test', $span->getAttributes()->get('gen_ai.request.model'));
        $this->assertSame(3, $span->getAttributes()->get('strands.tools.count'));
        $this->assertSame(
            ['availability_lookup', 'booking_confirmation'],
            $span->getAttributes()->get('strands.tools.names'),
        );
    }

    /**
     * Verifies the invoke observer marks error stop reason as failed so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testInvokeObserverMarksErrorStopReasonAsFailed(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/invoke', [], '{}');
        $this->middleware->afterInvoke('https://agent.example.com/invoke', AgentResponse::fromArray([
            'text' => '',
            'stop_reason' => 'error',
        ]), 40.0);
        $this->middleware->afterResponse('https://agent.example.com/invoke', 200, 40.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('agent terminal error', $span->getStatus()->getDescription());
        $this->assertSame('agent_error', $span->getAttributes()->get('error.type'));
        $this->assertSame('error', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(0, $span->getAttributes()->get('strands.tools.count'));
        $this->assertNull($span->getAttributes()->get('strands.tools.names'));
    }

    /**
     * Verifies the invoke observer exports SDK limit stop reason so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testInvokeObserverExportsSdkLimitStopReason(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/invoke', [], '{}');
        $this->middleware->afterInvoke('https://agent.example.com/invoke', AgentResponse::fromArray([
            'text' => '',
            'stop_reason' => 'limit_turns',
        ]), 40.0);
        $this->middleware->afterResponse('https://agent.example.com/invoke', 200, 40.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame('limit_turns', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Verifies the custom JSON observer exports only safe summary fields so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testPostJsonObserverExportsOnlySafeSummaryFields(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/custom', [], '{}');
        $this->middleware->afterPostJson('https://agent.example.com/custom', [
            'agent' => 'patient-secret-agent',
            'session_id' => 'patient-secret-session',
            'model' => 'patient-secret-model',
            'tools_used' => [
                ['name' => 'patient-secret-tool'],
                ['name' => 'another-secret-tool'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 7, 'output_tokens' => 3],
        ], 30.0);
        $this->middleware->afterResponse('https://agent.example.com/custom', 200, 30.0);

        $span = $this->exportedSpans()[0];
        $this->assertNull($span->getAttributes()->get('strands.agent.name'));
        $this->assertNull($span->getAttributes()->get('gen_ai.request.model'));
        $this->assertNull($span->getAttributes()->get('strands.tools.names'));
        $this->assertTrue($span->getAttributes()->get('strands.session.present'));
        $this->assertSame(2, $span->getAttributes()->get('strands.tools.count'));
        $this->assertSame(7, $span->getAttributes()->get('gen_ai.usage.input_tokens'));
        $this->assertSame('end_turn', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Verifies the custom JSON observer drops unknown stop reason so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testPostJsonObserverDropsUnknownStopReason(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/custom', [], '{}');
        $this->middleware->afterPostJson('https://agent.example.com/custom', [
            'stop_reason' => 'patient-secret-stop-reason',
        ], 30.0);
        $this->middleware->afterResponse('https://agent.example.com/custom', 200, 30.0);

        $span = $this->exportedSpans()[0];
        $this->assertNull($span->getAttributes()->get('gen_ai.response.finish_reason'));
    }

    /**
     * Verifies the raw SSE observer adds sanitized summary attributes so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testStreamSseObserverAddsSanitizedSummaryAttributes(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/file-summarise-stream', ['Accept' => 'text/event-stream'], '{}');
        $this->middleware->afterStreamSse('https://agent.example.com/file-summarise-stream', new StreamSseSummary(
            totalEvents: 3,
            textEvents: 2,
            cancelled: false,
            terminalType: 'complete',
            usage: new \StrandsPhpClient\Response\Usage(inputTokens: 20, outputTokens: 8),
            stopReason: 'end_turn',
        ), 55.0);
        $this->middleware->afterResponse('https://agent.example.com/file-summarise-stream', 200, 55.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame('stream_sse', $span->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame(3, $span->getAttributes()->get('strands.stream.total_events'));
        $this->assertSame(2, $span->getAttributes()->get('strands.stream.text_events'));
        $this->assertFalse($span->getAttributes()->get('strands.stream.cancelled'));
        $this->assertSame(20, $span->getAttributes()->get('gen_ai.usage.input_tokens'));
        $this->assertSame('end_turn', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Verifies standard operations below a base path are classified by their final segment.
     * This keeps telemetry useful without exposing request content.
     *
     * @return void
     */
    public function testBasePathStandardOperationsAreClassifiedByFinalSegment(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/prod/invoke', ['Accept' => 'application/json'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/prod/invoke', 200, 10.0);
        $this->middleware->beforeRequest('https://agent.example.com/agent/stream', ['accept' => 'text/event-stream'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/agent/stream', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('invoke', $spans[0]->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame('strands.client.stream', $spans[1]->getName());
        $this->assertSame('stream', $spans[1]->getAttributes()->get('gen_ai.operation.name'));
    }

    /**
     * Verifies custom SSE detection accepts header casing differences so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testCustomSseOperationUsesCaseInsensitiveAcceptHeader(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/custom-events', ['accept' => 'text/event-stream'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/custom-events', 200, 10.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame('stream_sse', $span->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame('strands.client.stream_sse', $span->getName());
    }

    /**
     * Verifies the typed-stream observer adds safe summary attributes so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testStreamObserverAddsSafeSummaryAttributes(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/stream', ['Accept' => 'text/event-stream'], '{}');
        $this->middleware->afterStream('https://agent.example.com/stream', new StreamResult(
            text: 'Done',
            sessionId: 'session-secret',
            usage: new Usage(inputTokens: 12, outputTokens: 4),
            toolsUsed: [
                ['name' => 'lookup'],
                ['name' => 'lookup'],
                ['name' => 'summarize'],
            ],
            textEvents: 2,
            totalEvents: 4,
            stopReason: StopReason::EndTurn,
            cancelled: false,
            timeToFirstTextTokenMs: 12.5,
            terminalType: 'complete',
        ), 40.0);
        $this->middleware->afterResponse('https://agent.example.com/stream', 200, 40.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame(12, $span->getAttributes()->get('gen_ai.usage.input_tokens'));
        $this->assertSame(4, $span->getAttributes()->get('gen_ai.usage.output_tokens'));
        $this->assertTrue($span->getAttributes()->get('strands.session.present'));
        $this->assertSame(2, $span->getAttributes()->get('strands.stream.text_events'));
        $this->assertSame(4, $span->getAttributes()->get('strands.stream.total_events'));
        $this->assertFalse($span->getAttributes()->get('strands.stream.cancelled'));
        $this->assertSame(12.5, $span->getAttributes()->get('strands.stream.ttft_ms'));
        $this->assertSame('end_turn', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(3, $span->getAttributes()->get('strands.tools.count'));
        $this->assertSame(['lookup', 'summarize'], $span->getAttributes()->get('strands.tools.names'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Verifies the typed-stream observer marks terminal error as failed so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testStreamObserverMarksTerminalErrorAsFailed(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/stream', ['Accept' => 'text/event-stream'], '{}');
        $this->middleware->afterStream('https://agent.example.com/stream', new StreamResult(
            text: '',
            terminalType: 'error',
            errorCode: 'agent_error',
            errorMessage: 'Agent failed',
        ), 40.0);
        $this->middleware->afterResponse('https://agent.example.com/stream', 200, 40.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('stream terminal error', $span->getStatus()->getDescription());
        $this->assertSame('stream_error', $span->getAttributes()->get('error.type'));
    }

    /**
     * Verifies the raw SSE observer marks terminal error as failed so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testStreamSseObserverMarksTerminalErrorAsFailed(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/custom-events', ['Accept' => 'text/event-stream'], '{}');
        $this->middleware->afterStreamSse('https://agent.example.com/custom-events', new StreamSseSummary(
            totalEvents: 1,
            terminalType: 'error',
        ), 20.0);
        $this->middleware->afterResponse('https://agent.example.com/custom-events', 200, 20.0);

        $span = $this->exportedSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('stream_sse terminal error', $span->getStatus()->getDescription());
        $this->assertSame('stream_sse_error', $span->getAttributes()->get('error.type'));
    }

    /**
     * Verifies response cleanup contains span-lifecycle failures after a caller result exists.
     * This preserves the result while operators still receive useful telemetry.
     *
     * @return void
     */
    public function testAfterResponseSwallowsSpanLifecycleExceptions(): void
    {
        $scope = $this->createMock(ScopeInterface::class);
        $scope->expects($this->once())->method('detach');

        $span = $this->createMock(SpanInterface::class);
        $span->method('activate')->willReturn($scope);
        $span->expects($this->once())->method('end')
            ->willThrowException(new \RuntimeException('span end failed'));

        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('startSpan')->willReturn($span);

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($spanBuilder);

        $throwingSpanMiddleware = OtelTracingMiddleware::create($tracer);
        $throwingSpanMiddleware->beforeRequest('https://agent.example.com/invoke', [], '{}');

        // A span exporter failure during teardown must stay invisible to the user whose agent request already completed.
        $throwingSpanMiddleware->afterResponse('https://agent.example.com/invoke', 200, 12.5);
    }
}

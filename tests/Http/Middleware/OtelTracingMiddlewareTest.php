<?php

declare(strict_types=1);

/**
 * Tests caller-visible Otel Tracing Middleware behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\Response\Usage;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Verifies Otel Tracing Middleware behavior that application users rely on.
 */
class OtelTracingMiddlewareTest extends TestCase
{
    /** Captures spans so tests can inspect what app telemetry would receive. */
    private InMemoryExporter $exporter;

    /** Owns the test tracer lifecycle for each middleware scenario. */
    private TracerProvider $tracerProvider;

    /** Middleware under test for agent request tracing. */
    private OtelTracingMiddleware $middleware;

    /**
     * Handle set up.
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
     * Handle tear down.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    /**
     * Supports the get spans step in the app-facing flow.
     *
     * @return list<ImmutableSpan> Value returned to app code.
     */
    private function getSpans(): array
    {
        $this->tracerProvider->forceFlush();

        return $this->exporter->getSpans();
    }

    /**
     * Verifies that happy path span attributes.
     *
     * @return void
     */
    public function testHappyPathSpanAttributes(): void
    {
        $result = $this->middleware->beforeRequest(
            'https://agent.example.com/invoke',
            ['Content-Type' => 'application/json'],
            '{"message":"hello"}',
        );

        $this->middleware->afterResponse(
            'https://agent.example.com/invoke',
            200,
            150.0,
        );

        $spans = $this->getSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('strands.client.invoke', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame('POST', $span->getAttributes()->get('http.request.method'));
        $this->assertSame('https://agent.example.com/invoke', $span->getAttributes()->get('url.full'));
        $this->assertSame('strands', $span->getAttributes()->get('gen_ai.system'));
        $this->assertSame('invoke', $span->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame('strands-otel-v1', $span->getAttributes()->get('strands.otel.policy'));
        $this->assertSame('/invoke', $span->getAttributes()->get('strands.endpoint.route'));
        $this->assertSame(200, $span->getAttributes()->get('http.response.status_code'));
        $this->assertSame(150.0, $span->getAttributes()->get('strands.operation.duration_ms'));
    }

    /**
     * Verifies that header injection contains traceparent.
     *
     * @return void
     */
    public function testHeaderInjectionContainsTraceparent(): void
    {
        $result = $this->middleware->beforeRequest(
            'https://agent.example.com/invoke',
            [],
            '{}',
        );

        $headers = $result['headers'];
        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            $headers['traceparent'],
        );

        $this->middleware->afterResponse('https://agent.example.com/invoke', 200, 50.0);

        $spans = $this->getSpans();
        $traceId = $spans[0]->getContext()->getTraceId();
        $this->assertStringContainsString($traceId, $headers['traceparent']);
    }

    /**
     * Verifies that HTTP error without exception.
     *
     * @return void
     */
    public function testHttpErrorWithoutException(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 400, 100.0);

        $spans = $this->getSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame(400, $span->getAttributes()->get('http.response.status_code'));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    /**
     * Verifies that thrown exception recorded.
     *
     * @return void
     */
    public function testThrownExceptionRecorded(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 0, 100.0, new \RuntimeException('boom'));

        $spans = $this->getSpans();
        $span = $spans[0];

        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('RuntimeException', $span->getAttributes()->get('error.type'));
        $this->assertSame('RuntimeException', $span->getStatus()->getDescription());

        $exceptionEvents = array_values(array_filter(
            $span->getEvents(),
            static fn ($event): bool => $event->getName() === 'exception',
        ));
        $this->assertNotEmpty($exceptionEvents, 'Span must record an "exception" event');
        $this->assertSame(\RuntimeException::class, $exceptionEvents[0]->getAttributes()->get('exception.type'));
        $this->assertSame('RuntimeException', $exceptionEvents[0]->getAttributes()->get('exception.message'));
        $this->assertTrue($exceptionEvents[0]->getAttributes()->get('exception.escaped'));
    }

    /**
     * Verifies that agent error exception sets strands status code.
     *
     * @return void
     */
    public function testAgentErrorExceptionSetsStrandsStatusCode(): void
    {
        $agentErrorException = new AgentErrorException(
            'Bad request',
            statusCode: 400,
            errorCode: 'patient-secret-validation-code',
        );

        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 400, 50.0, $agentErrorException);

        $spans = $this->getSpans();
        $this->assertSame(400, $spans[0]->getAttributes()->get('strands.error.status_code'));
        $this->assertNull($spans[0]->getAttributes()->get('strands.error.code'));
        $this->assertSame('AgentErrorException', $spans[0]->getAttributes()->get('error.type'));
        $this->assertSame('agent_http_400', $spans[0]->getStatus()->getDescription());
    }

    /**
     * Verifies that sequential operations on same instance.
     *
     * @return void
     */
    public function testSequentialOperationsOnSameInstance(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->afterResponse('https://x/stream', 200, 20.0);

        $spans = $this->getSpans();
        $this->assertCount(2, $spans);
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('strands.client.stream', $spans[1]->getName());
    }

    /**
     * Verifies that nested operations keep both active spans until their own responses finish.
     *
     * @return void
     */
    public function testNestedOperationsPreserveOuterSpan(): void
    {
        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 200, 5.0);
        $this->middleware->afterResponse('https://x/stream', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertCount(2, $spans);
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('strands.client.stream', $spans[1]->getName());
        $this->assertSame(StatusCode::STATUS_UNSET, $spans[1]->getStatus()->getCode());
    }

    /**
     * Verifies that query string stripping.
     *
     * @return void
     */
    public function testQueryStringStripping(): void
    {
        $this->middleware->beforeRequest('https://x/y?secret=abc&token=xyz', [], '{}');
        $this->middleware->afterResponse('https://x/y?secret=abc&token=xyz', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('https://x/{custom}', $spans[0]->getAttributes()->get('url.full'));
    }

    /**
     * Verifies that the status-zero cancellation sentinel is not exported as an HTTP status.
     *
     * @return void
     */
    public function testStatusZeroIsNotRecordedAsHttpStatus(): void
    {
        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->afterResponse('https://x/stream', 0, 10.0);

        $span = $this->getSpans()[0];
        $this->assertNull($span->getAttributes()->get('http.response.status_code'));
    }

    /**
     * Verifies that path segment span naming.
     *
     * @return void
     */
    public function testPathSegmentSpanNaming(): void
    {
        $urls = [
            'https://x/invoke' => 'strands.client.invoke',
            'https://x/file-summarise-stream' => 'strands.client.post_json',
            'https://x/' => 'strands.client.post_json',
            'https://x' => 'strands.client.post_json',
        ];

        foreach ($urls as $url => $expectedName) {
            $this->middleware->beforeRequest($url, [], '{}');
            $this->middleware->afterResponse($url, 200, 10.0);
        }

        $spans = $this->getSpans();
        $this->assertCount(count($urls), $spans);

        $i = 0;
        foreach ($urls as $url => $expectedName) {
            $this->assertSame($expectedName, $spans[$i]->getName(), "Failed for URL: $url");
            $i++;
        }
    }

    /**
     * Verifies that an app-supplied span prefix is preserved.
     *
     * @return void
     */
    public function testCustomSpanNamePrefixIsPreserved(): void
    {
        $middleware = new OtelTracingMiddleware(
            $this->tracerProvider->getTracer('custom-prefix'),
            TraceContextPropagator::getInstance(),
            'app.agent',
        );

        $middleware->beforeRequest('https://x/invoke', [], '{}');
        $middleware->afterResponse('https://x/invoke', 200, 10.0);

        $this->assertSame('app.agent.invoke', $this->getSpans()[0]->getName());
    }

    /**
     * Verifies that after response on empty stack does not throw.
     *
     * @return void
     */
    public function testAfterResponseOnEmptyStackDoesNotThrow(): void
    {
        // afterResponse called without a matching beforeRequest should not throw
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertCount(0, $spans);
    }

    /**
     * Verifies that server address and port attributes.
     *
     * @return void
     */
    public function testServerAddressAndPortAttributes(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com:8443/invoke', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com:8443/invoke', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('agent.example.com', $spans[0]->getAttributes()->get('server.address'));
        $this->assertSame(8443, $spans[0]->getAttributes()->get('server.port'));
        $this->assertSame('https://agent.example.com:8443/invoke', $spans[0]->getAttributes()->get('url.full'));
    }

    /**
     * Verifies that arbitrary custom paths fail closed to a stable route label.
     *
     * @return void
     */
    public function testCustomPathsCollapseToStableTelemetryLabels(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/session/sess-secret-123/history?token=abc', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com/session/sess-secret-123/history?token=abc', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('strands.client.post_json', $spans[0]->getName());
        $this->assertSame('/{custom}', $spans[0]->getAttributes()->get('strands.endpoint.route'));
        $this->assertSame('https://agent.example.com/{custom}', $spans[0]->getAttributes()->get('url.full'));
    }

    /**
     * Verifies that response observer adds invoke attributes before span ends.
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

        $span = $this->getSpans()[0];
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
     * Verifies that invoke stop reasons can mark an HTTP-success span as failed.
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

        $span = $this->getSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('agent terminal error', $span->getStatus()->getDescription());
        $this->assertSame('agent_error', $span->getAttributes()->get('error.type'));
        $this->assertSame(0, $span->getAttributes()->get('strands.tools.count'));
        $this->assertNull($span->getAttributes()->get('strands.tools.names'));
    }

    /**
     * Verifies that custom response telemetry exports only normalized summaries.
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

        $span = $this->getSpans()[0];
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
     * Verifies that unknown custom stop reasons are not exported as telemetry labels.
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

        $span = $this->getSpans()[0];
        $this->assertNull($span->getAttributes()->get('gen_ai.response.finish_reason'));
    }

    /**
     * Verifies that stream SSE observer adds sanitized summary attributes.
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

        $span = $this->getSpans()[0];
        $this->assertSame('stream_sse', $span->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame(3, $span->getAttributes()->get('strands.stream.total_events'));
        $this->assertSame(2, $span->getAttributes()->get('strands.stream.text_events'));
        $this->assertFalse($span->getAttributes()->get('strands.stream.cancelled'));
        $this->assertSame(20, $span->getAttributes()->get('gen_ai.usage.input_tokens'));
        $this->assertSame('end_turn', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Verifies that base-path endpoints still classify standard invoke and stream operations.
     *
     * @return void
     */
    public function testBasePathStandardOperationsAreClassifiedByFinalSegment(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/prod/invoke', ['Accept' => 'application/json'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/prod/invoke', 200, 10.0);
        $this->middleware->beforeRequest('https://agent.example.com/agent/stream', ['accept' => 'text/event-stream'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/agent/stream', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('invoke', $spans[0]->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame('strands.client.stream', $spans[1]->getName());
        $this->assertSame('stream', $spans[1]->getAttributes()->get('gen_ai.operation.name'));
    }

    /**
     * Verifies that custom SSE operation detection reads Accept case-insensitively.
     *
     * @return void
     */
    public function testCustomSseOperationUsesCaseInsensitiveAcceptHeader(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/custom-events', ['accept' => 'text/event-stream'], '{}');
        $this->middleware->afterResponse('https://agent.example.com/custom-events', 200, 10.0);

        $span = $this->getSpans()[0];
        $this->assertSame('stream_sse', $span->getAttributes()->get('gen_ai.operation.name'));
        $this->assertSame('strands.client.stream_sse', $span->getName());
    }

    /**
     * Verifies that typed stream summaries populate every safe telemetry field.
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

        $span = $this->getSpans()[0];
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
     * Verifies that typed stream terminal error events mark spans as failed.
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

        $span = $this->getSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('stream terminal error', $span->getStatus()->getDescription());
        $this->assertSame('stream_error', $span->getAttributes()->get('error.type'));
    }

    /**
     * Verifies that raw SSE terminal error events mark spans as failed.
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

        $span = $this->getSpans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('stream_sse terminal error', $span->getStatus()->getDescription());
        $this->assertSame('stream_sse_error', $span->getAttributes()->get('error.type'));
    }

    /**
     * Verifies that after response swallows span lifecycle exceptions.
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

        // Tracing must never break the user's request: a span whose end() throws
        // inside afterResponse() is swallowed per the middleware contract.
        $throwingSpanMiddleware->afterResponse('https://agent.example.com/invoke', 200, 12.5);
    }
}

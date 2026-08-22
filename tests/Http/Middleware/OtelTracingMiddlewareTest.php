<?php

declare(strict_types=1);

/**
 * Exercises the tracing data emitted around caller-visible agent operations.
 * It mirrors app requests that succeed, fail, stream, or use custom routes.
 * Failures here mean a telemetry screen could be wrong, unsafe, or incomplete.
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
 * Verifies tracing stays useful without exposing prompts, responses, or session identifiers.
 *
 * It protects the spans an operator sees while a user invokes or streams an agent response.
 * Use these scenarios when changing request middleware, response observers, or telemetry labels.
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
     * Supports the related telemetry scenario (get spans).
     * Use it when span naming, attributes, or middleware lifecycle needs this shared setup.
     *
     * @return list<ImmutableSpan> Value returned to app code.
     */
    private function getSpans(): array
    {
        $this->tracerProvider->forceFlush();

        return $this->exporter->getSpans();
    }

    /**
     * Covers "happy path span attributes" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "header injection contains traceparent" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "http error without exception" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "thrown exception recorded" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "agent error exception sets strands status code" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "sequential operations on same instance" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "nested operations preserve outer span" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "query string stripping" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "status zero is not recorded as http status" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "path segment span naming" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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

        // Exercise every caller-visible route shape before inspecting the exported spans together.
        foreach ($urls as $url => $expectedName) {
            $this->middleware->beforeRequest($url, [], '{}');
            $this->middleware->afterResponse($url, 200, 10.0);
        }

        $spans = $this->getSpans();
        $this->assertCount(count($urls), $spans);

        $spanIndex = 0;
        // Match each exported span back to the route that produced it so telemetry naming remains stable for operators.
        foreach ($urls as $url => $expectedName) {
            $this->assertSame($expectedName, $spans[$spanIndex]->getName(), "Failed for URL: $url");
            $spanIndex++;
        }
    }

    /**
     * Covers "custom span name prefix is preserved" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "after response on empty stack does not throw" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
     *
     * @return void
     */
    public function testAfterResponseOnEmptyStackDoesNotThrow(): void
    {
        // A stray teardown callback has no span to close and must not disrupt the user's request.
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertCount(0, $spans);
    }

    /**
     * Covers "server address and port attributes" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "custom paths collapse to stable telemetry labels" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "response observer adds invoke attributes before span ends" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "invoke observer marks error stop reason as failed" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
        $this->assertSame('error', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(0, $span->getAttributes()->get('strands.tools.count'));
        $this->assertNull($span->getAttributes()->get('strands.tools.names'));
    }

    /**
     * Covers "invoke observer exports sdk limit stop reason" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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

        $span = $this->getSpans()[0];
        $this->assertSame('limit_turns', $span->getAttributes()->get('gen_ai.response.finish_reason'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /**
     * Covers "post json observer exports only safe summary fields" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "post json observer drops unknown stop reason" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "stream sse observer adds sanitized summary attributes" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "base path standard operations are classified by final segment" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "custom sse operation uses case insensitive accept header" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "stream observer adds safe summary attributes" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "stream observer marks terminal error as failed" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "stream sse observer marks terminal error as failed" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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
     * Covers "after response swallows span lifecycle exceptions" so telemetry still explains the user's request.
     * Use this regression case when span naming, attributes, or middleware lifecycle changes.
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

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;

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
     * Verifies successful requests export the expected span attributes so operators get useful telemetry without exposed request content.
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

        $spans = $this->exportedSpans();
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
     * Verifies trace-header injection includes traceparent so operators get useful telemetry without exposed request content.
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

        $spans = $this->exportedSpans();
        $traceId = $spans[0]->getContext()->getTraceId();
        $this->assertStringContainsString($traceId, $headers['traceparent']);
    }

    /**
     * Verifies HTTP errors mark spans as failed even without exceptions so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testHttpErrorWithoutException(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 400, 100.0);

        $spans = $this->exportedSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame(400, $span->getAttributes()->get('http.response.status_code'));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    /**
     * Verifies thrown request exceptions are recorded on the active span so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testThrownExceptionRecorded(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 0, 100.0, new \RuntimeException('boom'));

        $spans = $this->exportedSpans();
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
     * Verifies agent errors export their Strands status code so operators get useful telemetry without exposed request content.
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

        $spans = $this->exportedSpans();
        $this->assertSame(400, $spans[0]->getAttributes()->get('strands.error.status_code'));
        $this->assertNull($spans[0]->getAttributes()->get('strands.error.code'));
        $this->assertSame('AgentErrorException', $spans[0]->getAttributes()->get('error.type'));
        $this->assertSame('agent_http_400', $spans[0]->getStatus()->getDescription());
    }

    /**
     * Verifies sequential calls on one middleware instance receive separate spans so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testSequentialOperationsOnSameInstance(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->afterResponse('https://x/stream', 200, 20.0);

        $spans = $this->exportedSpans();
        $this->assertCount(2, $spans);
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('strands.client.stream', $spans[1]->getName());
    }

    /**
     * Verifies nested calls restore the outer span so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testNestedOperationsPreserveOuterSpan(): void
    {
        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 200, 5.0);
        $this->middleware->afterResponse('https://x/stream', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertCount(2, $spans);
        $this->assertSame('strands.client.invoke', $spans[0]->getName());
        $this->assertSame('strands.client.stream', $spans[1]->getName());
        $this->assertSame(StatusCode::STATUS_UNSET, $spans[1]->getStatus()->getCode());
    }

    /**
     * Verifies span URLs omit query strings that may contain caller data so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testQueryStringStripping(): void
    {
        $this->middleware->beforeRequest('https://x/y?secret=abc&token=xyz', [], '{}');
        $this->middleware->afterResponse('https://x/y?secret=abc&token=xyz', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertSame('https://x/{custom}', $spans[0]->getAttributes()->get('url.full'));
    }

    /**
     * Verifies cancelled streams do not export status zero as an HTTP status so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testStatusZeroIsNotRecordedAsHttpStatus(): void
    {
        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->afterResponse('https://x/stream', 0, 10.0);

        $span = $this->exportedSpans()[0];
        $this->assertNull($span->getAttributes()->get('http.response.status_code'));
    }

    /**
     * Verifies span names use the caller-visible operation path so operators get useful telemetry without exposed request content.
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

        $spans = $this->exportedSpans();
        $this->assertCount(count($urls), $spans);

        $spanIndex = 0;
        // Match each exported span back to the route that produced it so telemetry naming remains stable for operators.
        foreach ($urls as $url => $expectedName) {
            $this->assertSame($expectedName, $spans[$spanIndex]->getName(), "Failed for URL: $url");
            $spanIndex++;
        }
    }

    /**
     * Verifies custom span name prefix is preserved so operators get useful telemetry without exposed request content.
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

        $this->assertSame('app.agent.invoke', $this->exportedSpans()[0]->getName());
    }

    /**
     * Verifies response cleanup is safe when no request span was started so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testAfterResponseOnEmptyStackDoesNotThrow(): void
    {
        // A stray teardown callback has no span to close and must not disrupt the user's request.
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertCount(0, $spans);
    }

    /**
     * Verifies spans identify the destination host and port so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testServerAddressAndPortAttributes(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com:8443/invoke', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com:8443/invoke', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertSame('agent.example.com', $spans[0]->getAttributes()->get('server.address'));
        $this->assertSame(8443, $spans[0]->getAttributes()->get('server.port'));
        $this->assertSame('https://agent.example.com:8443/invoke', $spans[0]->getAttributes()->get('url.full'));
    }

    /**
     * Verifies custom paths collapse to stable telemetry labels so operators get useful telemetry without exposed request content.
     *
     * @return void
     */
    public function testCustomPathsCollapseToStableTelemetryLabels(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/session/sess-secret-123/history?token=abc', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com/session/sess-secret-123/history?token=abc', 200, 10.0);

        $spans = $this->exportedSpans();
        $this->assertSame('strands.client.post_json', $spans[0]->getName());
        $this->assertSame('/{custom}', $spans[0]->getAttributes()->get('strands.endpoint.route'));
        $this->assertSame('https://agent.example.com/{custom}', $spans[0]->getAttributes()->get('url.full'));
    }

}

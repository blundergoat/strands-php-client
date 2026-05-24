<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamSseSummary;

class OtelTracingMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $tracerProvider;

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
     * @return list<ImmutableSpan>
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
        $this->assertSame('/invoke', $span->getAttributes()->get('strands.endpoint.route'));
        $this->assertSame(200, $span->getAttributes()->get('http.response.status_code'));
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
        $this->middleware->afterResponse('https://x/invoke', 503, 100.0);

        $spans = $this->getSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame(503, $span->getAttributes()->get('http.response.status_code'));
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
        $this->assertSame('RuntimeException', $exceptionEvents[0]->getAttributes()->get('exception.message'));
    }

    /**
     * Verifies that agent error exception sets strands status code.
     *
     * @return void
     */
    public function testAgentErrorExceptionSetsStrandsStatusCode(): void
    {
        $agentErrorException = new AgentErrorException('Bad request', statusCode: 400, errorCode: 'validation');

        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 400, 50.0, $agentErrorException);

        $spans = $this->getSpans();
        $this->assertSame(400, $spans[0]->getAttributes()->get('strands.error.status_code'));
        $this->assertSame('validation', $spans[0]->getAttributes()->get('strands.error.code'));
        $this->assertSame('agent_error:validation', $spans[0]->getStatus()->getDescription());
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
     * Verifies that query string stripping.
     *
     * @return void
     */
    public function testQueryStringStripping(): void
    {
        $this->middleware->beforeRequest('https://x/y?secret=abc&token=xyz', [], '{}');
        $this->middleware->afterResponse('https://x/y?secret=abc&token=xyz', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('https://x/y', $spans[0]->getAttributes()->get('url.full'));
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
            'https://x/file-summarise-stream' => 'strands.client.custom.file-summarise-stream',
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
     * Verifies that dynamic path segments are sanitized.
     *
     * @return void
     */
    public function testDynamicPathSegmentsAreSanitized(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com/session/sess-secret-123/history?token=abc', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com/session/sess-secret-123/history?token=abc', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('strands.client.custom.session.id.history', $spans[0]->getName());
        $this->assertSame('/session/{id}/history', $spans[0]->getAttributes()->get('strands.endpoint.route'));
        $this->assertSame('https://agent.example.com/session/{id}/history', $spans[0]->getAttributes()->get('url.full'));
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
        $this->assertSame(1, $span->getAttributes()->get('strands.tools.count'));
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
    }
}

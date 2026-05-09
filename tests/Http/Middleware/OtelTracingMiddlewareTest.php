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

class OtelTracingMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $tracerProvider;

    private OtelTracingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $tracer = $this->tracerProvider->getTracer('test');
        $this->middleware = OtelTracingMiddleware::create($tracer);
    }

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
        $this->assertSame('strands.client invoke', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame('POST', $span->getAttributes()->get('http.request.method'));
        $this->assertSame('https://agent.example.com/invoke', $span->getAttributes()->get('url.full'));
        $this->assertSame(200, $span->getAttributes()->get('http.response.status_code'));
    }

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

    public function testThrownExceptionRecorded(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 0, 100.0, new \RuntimeException('boom'));

        $spans = $this->getSpans();
        $span = $spans[0];

        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('RuntimeException', $span->getAttributes()->get('error.type'));

        $events = $span->getEvents();
        $this->assertNotEmpty($events);
        $exceptionEvent = null;
        foreach ($events as $event) {
            if ($event->getName() === 'exception') {
                $exceptionEvent = $event;
            }
        }
        $this->assertNotNull($exceptionEvent);
    }

    public function testAgentErrorExceptionSetsStrandsStatusCode(): void
    {
        $error = new AgentErrorException('Bad request', statusCode: 400, errorCode: 'validation');

        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 400, 50.0, $error);

        $spans = $this->getSpans();
        $this->assertSame(400, $spans[0]->getAttributes()->get('strands.error.status_code'));
    }

    public function testSequentialOperationsOnSameInstance(): void
    {
        $this->middleware->beforeRequest('https://x/invoke', [], '{}');
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $this->middleware->beforeRequest('https://x/stream', [], '{}');
        $this->middleware->afterResponse('https://x/stream', 200, 20.0);

        $spans = $this->getSpans();
        $this->assertCount(2, $spans);
        $this->assertSame('strands.client invoke', $spans[0]->getName());
        $this->assertSame('strands.client stream', $spans[1]->getName());
    }

    public function testQueryStringStripping(): void
    {
        $this->middleware->beforeRequest('https://x/y?secret=abc&token=xyz', [], '{}');
        $this->middleware->afterResponse('https://x/y?secret=abc&token=xyz', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('https://x/y', $spans[0]->getAttributes()->get('url.full'));
    }

    public function testPathSegmentSpanNaming(): void
    {
        $urls = [
            'https://x/invoke' => 'strands.client invoke',
            'https://x/file-summarise-stream' => 'strands.client file-summarise-stream',
            'https://x/' => 'strands.client request',
            'https://x' => 'strands.client request',
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

    public function testAfterResponseOnEmptyStackDoesNotThrow(): void
    {
        // afterResponse called without a matching beforeRequest should not throw
        $this->middleware->afterResponse('https://x/invoke', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertCount(0, $spans);
    }

    public function testServerAddressAndPortAttributes(): void
    {
        $this->middleware->beforeRequest('https://agent.example.com:8443/invoke', [], '{}');
        $this->middleware->afterResponse('https://agent.example.com:8443/invoke', 200, 10.0);

        $spans = $this->getSpans();
        $this->assertSame('agent.example.com', $spans[0]->getAttributes()->get('server.address'));
        $this->assertSame(8443, $spans[0]->getAttributes()->get('server.port'));
        $this->assertSame('https://agent.example.com:8443/invoke', $spans[0]->getAttributes()->get('url.full'));
    }
}

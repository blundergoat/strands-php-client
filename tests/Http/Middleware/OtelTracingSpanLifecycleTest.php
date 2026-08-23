<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;

/**
 * Exercises what happens to a tracing span when the tracer itself misbehaves around a user's agent call.
 *
 * Every request opens a span and activates a context, and both have to be released whether the call succeeds, fails, or never gets started.
 * A span left open outlives the request, so the next answer the user asks for is recorded underneath a request that already finished.
 * Use these tests when changing how beforeRequest() registers a span or how afterResponse() tears one down.
 */
class OtelTracingSpanLifecycleTest extends TestCase
{
    /**
     * Protects the span opened for a request whose setup fails before tracing can register it.
     * Without this the span would stay open for the life of the process and every later agent call would hang beneath it in the trace.
     *
     * @return void
     */
    public function testSpanIsEndedWhenRequestSetupFailsBeforeItReachesTheStack(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('setAttribute')->willThrowException(new \RuntimeException('attribute rejected'));
        $span->expects($this->never())->method('activate');
        $span->expects($this->once())->method('end');

        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('startSpan')->willReturn($span);

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($spanBuilder);

        $failingSetupMiddleware = OtelTracingMiddleware::create($tracer);

        try {
            $failingSetupMiddleware->beforeRequest('https://agent.example.com/invoke', [], '{}');
            $this->fail('beforeRequest() should surface the tracer failure so the caller sees why the request stopped.');
        } catch (\RuntimeException $expectedTracerFailure) {
            $this->assertSame('attribute rejected', $expectedTracerFailure->getMessage());
        }

        // StrandsClient still reports the setup failure, and with nothing registered that call must not touch the span a second time.
        $failingSetupMiddleware->afterResponse('https://agent.example.com/invoke', 0, 4.2, new \RuntimeException('setup failed'));
    }

    /**
     * Protects the span teardown when releasing the traced context fails partway through.
     * A tracer that cannot detach must not also skip ending the span, or the next request the user makes inherits a finished one as its parent.
     *
     * @return void
     */
    public function testSpanStillEndsWhenReleasingTheTracedContextFails(): void
    {
        $scope = $this->createMock(ScopeInterface::class);
        $scope->expects($this->once())->method('detach')
            ->willThrowException(new \RuntimeException('detach failed'));

        $span = $this->createMock(SpanInterface::class);
        $span->method('activate')->willReturn($scope);
        $span->expects($this->once())->method('end');

        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('startSpan')->willReturn($span);

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($spanBuilder);

        $failingDetachMiddleware = OtelTracingMiddleware::create($tracer);
        $failingDetachMiddleware->beforeRequest('https://agent.example.com/invoke', [], '{}');

        // The user's answer already arrived, so a context-storage failure during teardown must stay out of their result.
        $failingDetachMiddleware->afterResponse('https://agent.example.com/invoke', 200, 12.5);
    }
}

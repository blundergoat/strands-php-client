<?php

declare(strict_types=1);

/**
 * Tests caller-visible Otel Tracing Phi Safety behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;

/**
 * Verifies Otel Tracing Phi Safety behavior that application users rely on.
 */
final class OtelTracingPhiSafetyTest extends TestCase
{
    /** Captures spans so tests can check for sensitive values. */
    private InMemoryExporter $exporter;

    /** Owns the test tracer lifecycle for each PHI-safety scenario. */
    private TracerProvider $tracerProvider;

    /** Middleware under test for safe app telemetry. */
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
        $this->middleware = OtelTracingMiddleware::create($this->tracerProvider->getTracer('test'));
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
     * Verifies that span attributes do not contain sensitive payload values.
     *
     * @return void
     */
    public function testSpanAttributesDoNotContainSensitivePayloadValues(): void
    {
        $sessionId = 'session-secret-123';
        $prompt = 'patient asks about a sensitive referral';
        $responseText = 'sensitive response text';
        $documentBase64 = 'JVBERi0xLjQK-secret-document';
        $filename = 'referral-secret.pdf';
        $toolInput = 'raw private tool input';
        $citationSource = 'source text containing PHI';

        $this->middleware->beforeRequest(
            "https://agent.example.com/session/{$sessionId}/history?token=secret",
            ['Accept' => 'application/json'],
            json_encode([
                'message' => $prompt,
                'session_id' => $sessionId,
                'file_base64' => $documentBase64,
                'file_name' => $filename,
                'context' => ['metadata' => ['patient' => 'private context']],
            ], JSON_THROW_ON_ERROR),
        );

        $this->middleware->afterPostJson(
            "https://agent.example.com/session/{$sessionId}/history?token=secret",
            [
                'text' => $responseText,
                'session_id' => $sessionId,
                'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
                'tools_used' => [
                    [
                        'name' => 'lookup',
                        'input' => ['raw' => $toolInput],
                        'result' => ['source' => $citationSource],
                    ],
                ],
                'metadata' => ['filename' => $filename],
            ],
            25.0,
        );
        $this->middleware->afterResponse(
            "https://agent.example.com/session/{$sessionId}/history?token=secret",
            200,
            25.0,
        );

        $immutableSpan = $this->getOnlySpan();
        $serializedAttributes = json_encode($this->spanAttributes($immutableSpan), JSON_THROW_ON_ERROR);

        foreach ([$sessionId, $prompt, $responseText, $documentBase64, $filename, $toolInput, $citationSource, 'private context', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $serializedAttributes,
                sprintf('Sensitive payload value %s leaked into span attributes', var_export($forbidden, true)),
            );
        }

        $this->assertSame('/session/{id}/history', $immutableSpan->getAttributes()->get('strands.endpoint.route'));
        $this->assertTrue($immutableSpan->getAttributes()->get('strands.session.present'));
    }

    /**
     * Handle get only span.
     *
     * @return ImmutableSpan Value produced by the method.
     */
    private function getOnlySpan(): ImmutableSpan
    {
        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        $this->assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * Supports the span attributes step in the app-facing flow.
     *
     * @param ImmutableSpan $immutableSpan Value supplied by app code.
     * @return array<string, mixed> Span data used to prove telemetry stays safe for app users.
     */
    private function spanAttributes(ImmutableSpan $immutableSpan): array
    {
        $attributes = [];
        foreach ($immutableSpan->getAttributes() as $key => $value) {
            if (is_string($key)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }
}

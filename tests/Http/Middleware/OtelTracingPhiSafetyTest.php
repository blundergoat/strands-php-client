<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Http\Middleware;

use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Http\Middleware\OtelTracingMiddleware;

final class OtelTracingPhiSafetyTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $tracerProvider;

    private OtelTracingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->middleware = OtelTracingMiddleware::create($this->tracerProvider->getTracer('test'));
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

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

        $span = $this->getOnlySpan();
        $serializedAttributes = json_encode($this->spanAttributes($span), JSON_THROW_ON_ERROR);

        foreach ([$sessionId, $prompt, $responseText, $documentBase64, $filename, $toolInput, $citationSource, 'private context', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serializedAttributes);
        }

        $this->assertSame('/session/{id}/history', $span->getAttributes()->get('strands.endpoint.route'));
        $this->assertTrue($span->getAttributes()->get('strands.session.present'));
    }

    private function getOnlySpan(): ImmutableSpan
    {
        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        $this->assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function spanAttributes(ImmutableSpan $span): array
    {
        $attributes = [];
        foreach ($span->getAttributes() as $key => $value) {
            if (is_string($key)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }
}

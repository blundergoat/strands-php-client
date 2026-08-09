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
use StrandsPhpClient\Exceptions\AgentErrorException;
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
        $agentName = 'patient-secret-agent';
        $modelName = 'patient-secret-model';
        $toolName = 'patient-secret-tool';
        $stopReason = 'patient-secret-stop-reason';

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
                'agent' => $agentName,
                'model' => $modelName,
                'stop_reason' => $stopReason,
                'tools_used' => [
                    [
                        'name' => $toolName,
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
        $serializedSpan = $this->serializeSpan($immutableSpan);

        foreach ([$sessionId, $prompt, $responseText, $documentBase64, $filename, $toolInput, $citationSource, $agentName, $modelName, $toolName, $stopReason, 'private context', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $serializedSpan,
                sprintf('Sensitive payload value %s leaked into span telemetry', var_export($forbidden, true)),
            );
        }

        $this->assertSame('strands.client.post_json', $immutableSpan->getName());
        $this->assertSame('/{custom}', $immutableSpan->getAttributes()->get('strands.endpoint.route'));
        $this->assertTrue($immutableSpan->getAttributes()->get('strands.session.present'));
        $this->assertSame(1, $immutableSpan->getAttributes()->get('strands.tools.count'));
    }

    /**
     * Verifies that agent HTTP failures do not export app-owned error text or codes.
     *
     * @return void
     */
    public function testAgentErrorTelemetryUsesOnlyStableLabels(): void
    {
        $message = 'patient-secret-error-message';
        $errorCode = 'patient-secret-error-code';

        $this->middleware->beforeRequest('https://agent.example.com/invoke', [], '{}');
        $this->middleware->afterResponse(
            'https://agent.example.com/invoke',
            422,
            25.0,
            new AgentErrorException($message, statusCode: 422, errorCode: $errorCode),
        );

        $immutableSpan = $this->getOnlySpan();
        $serializedSpan = $this->serializeSpan($immutableSpan);

        $this->assertStringNotContainsString($message, $serializedSpan);
        $this->assertStringNotContainsString($errorCode, $serializedSpan);
        $this->assertSame('agent_http_422', $immutableSpan->getStatus()->getDescription());
        $this->assertSame(422, $immutableSpan->getAttributes()->get('strands.error.status_code'));
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

    /**
     * Serialize every exported span surface checked for sensitive values.
     *
     * @param ImmutableSpan $immutableSpan Span captured by the in-memory exporter.
     * @return string JSON representation of the span name, status, attributes, and events.
     */
    private function serializeSpan(ImmutableSpan $immutableSpan): string
    {
        $events = [];
        foreach ($immutableSpan->getEvents() as $event) {
            $eventAttributes = [];
            foreach ($event->getAttributes() as $key => $value) {
                if (is_string($key)) {
                    $eventAttributes[$key] = $value;
                }
            }
            $events[] = [
                'name' => $event->getName(),
                'attributes' => $eventAttributes,
            ];
        }

        return json_encode([
            'name' => $immutableSpan->getName(),
            'status' => $immutableSpan->getStatus()->getDescription(),
            'attributes' => $this->spanAttributes($immutableSpan),
            'events' => $events,
        ], JSON_THROW_ON_ERROR);
    }
}

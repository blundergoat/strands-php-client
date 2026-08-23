<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Config\StrandsConfig;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Http\HttpTransport;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

/**
 * Verifies real consumer profiles still work through typed streams and custom JSON or SSE endpoints.
 *
 * Use these tests when changing wire parsing, custom endpoint handling, or forward-compatible fields.
 * They protect domain-specific response data that calling applications read directly.
 */
class ConsumerCompatibilityTest extends TestCase
{
    /**
     * Confirms summit chatroom standard stream profile still parses typed events so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testSummitChatroomStandardStreamProfileStillParsesTypedEvents(): void
    {
        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->loadFixtureContents('consumer-summit-stream.sse')),
        );

        $events = [];
        $result = $strandsClient->stream(
            message: 'Start a summit turn',
            onEvent: function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            },
            context: AgentContext::create()
                ->withMetadata('persona', 'moderator')
                ->withMetadata('correlation_id', 'corr-001'),
            sessionId: 'summit-session-001',
        );

        $this->assertCount(5, $events);
        $this->assertSame(StreamEventType::Thinking, $events[0]->type);
        $this->assertSame(StreamEventType::ToolUse, $events[1]->type);
        $this->assertSame(['persona' => 'moderator'], $events[1]->toolInput);
        $this->assertSame(StreamEventType::ToolResult, $events[2]->type);
        $this->assertSame(StreamEventType::Complete, $events[4]->type);
        $this->assertTrue($events[4]->hasObjective);
        $this->assertTrue($result->text !== '');
        $this->assertSame('summit-session-001', $result->sessionId);
        $this->assertSame(32, $result->usage->inputTokens);
    }

    /**
     * Confirms ambient scribe custom post JSON profiles preserve raw arrays so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testAmbientScribeCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/session/session-001/history' => $this->loadJsonFixture('custom-ambient-history-response.json'),
            'http://wrapper.test/session/session-001/roles' => $this->loadJsonFixture('custom-ambient-roles-response.json'),
        ];

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransportReturning($responses),
        );

        $history = $strandsClient->postJson('/session/session-001/history', [], timeout: 10);
        $roles = $strandsClient->postJson('/session/session-001/roles', [], timeout: 5);

        $this->assertSame('session-001', $history['session_id']);
        $this->assertCount(2, $history['turns']);
        $this->assertSame('clinician', $roles['roles'][0]['role']);
        $this->assertSame(0.97, $roles['roles'][0]['confidence']);
    }

    /**
     * Confirms halaxy custom post JSON profiles preserve raw arrays so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testHalaxyCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/file-metadata' => $this->loadJsonFixture('custom-halaxy-file-metadata-response.json'),
            'http://wrapper.test/suggested-actions/analyse' => $this->loadJsonFixture('custom-halaxy-suggested-actions-analysis-response.json'),
        ];

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransportReturning($responses),
        );

        $metadata = $strandsClient->postJson('/file-metadata', [
            'file_base64' => 'redacted-base64',
            'file_name' => 'referral.pdf',
            'mime_type' => 'application/pdf',
        ], timeout: 120);
        $analysis = $strandsClient->postJson('/suggested-actions/analyse', [
            'conversation_id' => 'conv-001',
        ], timeout: 60);

        $this->assertSame('referral', $metadata['document_type']);
        $this->assertSame(421.8, $metadata['usage']['latency_ms']);
        $this->assertSame('action-001', $analysis['suggested_actions'][0]['id']);
        $this->assertSame(220, $analysis['usage']['input_tokens']);
    }

    /**
     * Confirms healthkit custom post JSON profiles preserve raw arrays so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testHealthkitCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/chat' => $this->loadJsonFixture('custom-healthkit-chat-response.json'),
            'http://wrapper.test/intent' => $this->loadJsonFixture('custom-healthkit-intent-response.json'),
        ];

        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransportReturning($responses),
        );

        $chat = $strandsClient->postJson('/chat', ['message' => 'Hi'], timeout: 45);
        $intent = $strandsClient->postJson('/intent', ['message' => 'Book appointment'], timeout: 15);

        $this->assertSame('I can help with that.', $chat['message']);
        $this->assertSame('show_booking_options', $chat['actions'][0]['type']);
        $this->assertSame('book_appointment', $intent['intent']);
        $this->assertSame('consultation', $intent['entities']['appointment_type']);
    }

    /**
     * Confirms custom stream SSE profiles preserve unknown fields for callbacks so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testCustomStreamSseProfilesPreserveUnknownFieldsForCallbacks(): void
    {
        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->loadFixtureContents('custom-halaxy-file-summarise-stream.sse')),
        );

        $events = [];
        $strandsClient->streamSse('/file-summarise-stream', [
            'file_base64' => 'redacted-base64',
            'file_name' => 'referral.pdf',
        ], function (array $event) use (&$events): void {
            $events[] = $event;
        }, timeout: 120);

        $this->assertCount(3, $events);
        $this->assertSame('progress', $events[0]['type']);
        $this->assertSame(25, $events[0]['percent']);
        $this->assertSame('The document requests a follow-up appointment.', $events[2]['summary']);
        $this->assertSame(810.4, $events[2]['usage']['latency_ms']);
    }

    /**
     * Confirms healthkit booking stream SSE profile preserves domain events so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testHealthkitBookingStreamSseProfilePreservesDomainEvents(): void
    {
        $strandsClient = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->loadFixtureContents('custom-healthkit-booking-respond-stream.sse')),
        );

        $events = [];
        $strandsClient->streamSse('/respond-stream', [
            'message' => 'Need an appointment',
            'session_id' => 'booking-session-001',
        ], function (array $event) use (&$events): void {
            $events[] = $event;
        }, timeout: 120);

        $this->assertCount(3, $events);
        $this->assertSame('booking_options', $events[1]['type']);
        $this->assertSame('slot-001', $events[1]['options'][0]['slot_id']);
        $this->assertSame('end_turn', $events[2]['stop_reason']);
    }

    /**
     * Builds a POST-only transport that returns the fixture mapped to each custom endpoint URL.
     * Use it to verify consumer-specific JSON remains available to the calling application.
     *
     * @param array<string, array<string, mixed>> $responses URL-keyed fixture responses; empty means every POST is unexpected.
     * @return HttpTransport Controlled transport used by the custom-endpoint scenarios.
     */
    private function postTransportReturning(array $responses): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')
            ->willReturnCallback(function (string $url) use ($responses): array {
                TestCase::assertArrayHasKey($url, $responses);

                return $responses[$url];
            });

        return $transport;
    }

    /**
     * Builds a streaming transport that delivers one captured SSE fixture to the caller callback.
     * Use it to exercise a real consumer event profile without network access.
     *
     * @param string $sseData Captured event bytes; empty means the callback receives no event data.
     * @return HttpTransport Controlled transport used by typed or raw streaming scenarios.
     */
    private function streamingTransport(string $sseData): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (
                string $url,
                array $headers,
                string $body,
                int $timeout,
                int $connectTimeout,
                callable $onChunk,
            ) use ($sseData): void {
                $onChunk->__invoke($sseData);
            });

        return $transport;
    }

    /**
     * Loads one captured Wire Contract fixture exactly as a consuming wrapper emitted it.
     * Use it when a compatibility scenario needs raw JSON or SSE bytes.
     *
     * @param string $filename Basename under the wire-contract fixture directory; empty cannot identify a fixture.
     * @return string Captured fixture bytes; never empty for the selected compatibility profiles.
     */
    private function loadFixtureContents(string $filename): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/wire-contract/' . $filename);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Decodes one captured JSON fixture into the raw map returned by a custom endpoint.
     * Use it when the application consumes domain fields outside the typed invoke contract.
     *
     * @param string $filename JSON fixture basename; empty cannot identify a captured response.
     * @return array<string, mixed> Decoded response map; never empty for these consumer profiles.
     */
    private function loadJsonFixture(string $filename): array
    {
        $fixtureData = json_decode($this->loadFixtureContents($filename), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixtureData);

        /** @var array<string, mixed> $fixtureData validated before app code uses it. */
        return $fixtureData;
    }
}

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

class ConsumerCompatibilityTest extends TestCase
{
    public function testSummitChatroomStandardStreamProfileStillParsesTypedEvents(): void
    {
        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->fixture('consumer-summit-stream.sse')),
        );

        $events = [];
        $result = $client->stream(
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

    public function testAmbientScribeCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/session/session-001/history' => $this->jsonFixture('custom-ambient-history-response.json'),
            'http://wrapper.test/session/session-001/roles' => $this->jsonFixture('custom-ambient-roles-response.json'),
        ];

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransport($responses),
        );

        $history = $client->postJson('/session/session-001/history', [], timeout: 10);
        $roles = $client->postJson('/session/session-001/roles', [], timeout: 5);

        $this->assertSame('session-001', $history['session_id']);
        $this->assertCount(2, $history['turns']);
        $this->assertSame('clinician', $roles['roles'][0]['role']);
        $this->assertSame(0.97, $roles['roles'][0]['confidence']);
    }

    public function testHalaxyCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/file-metadata' => $this->jsonFixture('custom-halaxy-file-metadata-response.json'),
            'http://wrapper.test/suggested-actions/analyse' => $this->jsonFixture('custom-halaxy-suggested-actions-analysis-response.json'),
        ];

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransport($responses),
        );

        $metadata = $client->postJson('/file-metadata', [
            'file_base64' => 'redacted-base64',
            'file_name' => 'referral.pdf',
            'mime_type' => 'application/pdf',
        ], timeout: 120);
        $analysis = $client->postJson('/suggested-actions/analyse', [
            'conversation_id' => 'conv-001',
        ], timeout: 60);

        $this->assertSame('referral', $metadata['document_type']);
        $this->assertSame(421.8, $metadata['usage']['latency_ms']);
        $this->assertSame('action-001', $analysis['suggested_actions'][0]['id']);
        $this->assertSame(220, $analysis['usage']['input_tokens']);
    }

    public function testHealthkitCustomPostJsonProfilesPreserveRawArrays(): void
    {
        $responses = [
            'http://wrapper.test/chat' => $this->jsonFixture('custom-healthkit-chat-response.json'),
            'http://wrapper.test/intent' => $this->jsonFixture('custom-healthkit-intent-response.json'),
        ];

        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->postTransport($responses),
        );

        $chat = $client->postJson('/chat', ['message' => 'Hi'], timeout: 45);
        $intent = $client->postJson('/intent', ['message' => 'Book appointment'], timeout: 15);

        $this->assertSame('I can help with that.', $chat['message']);
        $this->assertSame('show_booking_options', $chat['actions'][0]['type']);
        $this->assertSame('book_appointment', $intent['intent']);
        $this->assertSame('consultation', $intent['entities']['appointment_type']);
    }

    public function testCustomStreamSseProfilesPreserveUnknownFieldsForCallbacks(): void
    {
        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->fixture('custom-halaxy-file-summarise-stream.sse')),
        );

        $events = [];
        $client->streamSse('/file-summarise-stream', [
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

    public function testHealthkitBookingStreamSseProfilePreservesDomainEvents(): void
    {
        $client = new StrandsClient(
            config: new StrandsConfig(endpoint: 'http://wrapper.test'),
            transport: $this->streamingTransport($this->fixture('custom-healthkit-booking-respond-stream.sse')),
        );

        $events = [];
        $client->streamSse('/respond-stream', [
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
     * @param array<string, array<string, mixed>> $responses
     */
    private function postTransport(array $responses): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')
            ->willReturnCallback(function (string $url) use ($responses): array {
                TestCase::assertArrayHasKey($url, $responses);

                return $responses[$url];
            });

        return $transport;
    }

    private function streamingTransport(string $sseData): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('stream')
            ->willReturnCallback(function (string $url, array $headers, string $body, int $timeout, int $connectTimeout, callable $onChunk) use ($sseData): void {
                $onChunk($sseData);
            });

        return $transport;
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/wire-contract/' . $filename);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFixture(string $filename): array
    {
        $data = json_decode($this->fixture($filename), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}

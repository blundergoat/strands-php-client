<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Citation\Citation;
use StrandsPhpClient\Response\GuardrailTrace;
use StrandsPhpClient\Response\InterruptDetail;
use StrandsPhpClient\Response\StopReason;

/**
 * Verifies interrupts, guardrail traces, and citations survive response hydration for the calling app.
 *
 * Use these tests when changing interaction metadata or nested response parsing.
 * They protect approval prompts, safety notices, and source links shown after an agent turn.
 */
class AgentResponseInteractionTest extends TestCase
{
    /**
     * Loads captured fixture data for a realistic response-hydration scenario.
     * Use it when a test needs the same payload an app could receive from an agent.
     *
     * @param string $relativePath Non-empty path relative to tests/Fixtures/.
     * @return array<string, mixed> Decoded fixture fields; never empty in these scenarios.
     */
    private function loadJsonFixture(string $relativePath): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/' . $relativePath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Verifies AgentResponse::fromArray() parses interrupts so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayParsesInterrupts(): void
    {
        $responseData = $this->loadJsonFixture('invoke-interrupt-response.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertTrue($agentResponse->isInterrupted());
        $this->assertCount(1, $agentResponse->interrupts);
        $this->assertInstanceOf(InterruptDetail::class, $agentResponse->interrupts[0]);
        $this->assertSame('deploy', $agentResponse->interrupts[0]->toolName);
        $this->assertSame(['environment' => 'production', 'version' => '2.0.0'], $agentResponse->interrupts[0]->toolInput);
        $this->assertSame('tu-001', $agentResponse->interrupts[0]->toolUseId);
        $this->assertSame('int-abc-123', $agentResponse->interrupts[0]->interruptId);
        $this->assertSame('Production deployment requires approval', $agentResponse->interrupts[0]->reason);
        $this->assertSame(StopReason::Interrupt, $agentResponse->stopReason);
    }

    /**
     * Verifies AgentResponse::fromArray() defaults interrupts to an empty list when none arrive so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayNoInterruptsDefaultsEmpty(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertFalse($agentResponse->isInterrupted());
        $this->assertSame([], $agentResponse->interrupts);
    }

    /**
     * Verifies AgentResponse::fromArray() parses guardrail trace so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayParsesGuardrailTrace(): void
    {
        $responseData = $this->loadJsonFixture('invoke-guardrail-response.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertNotNull($agentResponse->guardrailTrace);
        $this->assertInstanceOf(GuardrailTrace::class, $agentResponse->guardrailTrace);
        $this->assertSame('INTERVENED', $agentResponse->guardrailTrace->action);
        $this->assertCount(1, $agentResponse->guardrailTrace->assessments);
        $this->assertSame('content_filter', $agentResponse->guardrailTrace->assessments[0]['type']);
        $this->assertSame('The original unsafe response text', $agentResponse->guardrailTrace->modelOutput);
        $this->assertSame(StopReason::GuardrailIntervened, $agentResponse->stopReason);
    }

    /**
     * Builds a response whose nested trace reports a guardrail intervention.
     * Use it to verify the app receives normalized GuardrailTrace details.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadForNestedGuardrailTrace(): array
    {
        return [
            'text' => 'Blocked',
            'trace' => [
                'guardrail' => [
                    'action' => 'INTERVENED',
                    'assessments' => [],
                ],
            ],
        ];
    }

    /**
     * Verifies AgentResponse::fromArray() reads guardrail details from nested trace data so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceFromNestedTrace(): void
    {
        $responseData = $this->responsePayloadForNestedGuardrailTrace();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertNotNull($agentResponse->guardrailTrace);
        $this->assertSame('INTERVENED', $agentResponse->guardrailTrace->action);
    }

    /**
     * Verifies AgentResponse::fromArray() defaults guardrail details to null when none arrive so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayGuardrailTraceDefaultsToNull(): void
    {
        // Keep this separate from generic null defaults because GuardrailTrace hydration follows its own app-visible parsing path first.
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertNull($agentResponse->guardrailTrace);
    }

    /**
     * Verifies AgentResponse::fromArray() parses citations so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayParsesCitations(): void
    {
        $responseData = $this->loadJsonFixture('invoke-response-with-citations.json');

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(1, $agentResponse->citations);
        $this->assertSame('citationsContent', $agentResponse->citations[0]['type']);
        $this->assertSame('https://example.com/docs', $agentResponse->citations[0]['source']);
        $this->assertSame('Official Documentation', $agentResponse->citations[0]['title']);
    }

    /**
     * Verifies AgentResponse::fromArray() defaults citations to an empty list when none arrive so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayCitationsDefaultsToEmpty(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->citations);
    }

    /**
     * Builds a response that mixes citation and non-citation content blocks.
     * Use it to verify only source links reach the app's citation list.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithMixedCitationBlocks(): array
    {
        return [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'citationsContent', 'source' => 'url'],
                    ['type' => 'image', 'data' => 'abc'],
                ],
            ],
        ];
    }

    /**
     * Verifies AgentResponse::fromArray() ignores non-citation blocks while collecting sources so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testFromArrayCitationsIgnoresNonCitationBlocks(): void
    {
        $responseData = $this->responsePayloadWithMixedCitationBlocks();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(1, $agentResponse->citations);
        $this->assertSame('citationsContent', $agentResponse->citations[0]['type']);
    }

    /**
     * Builds a response containing two independent approval interruptions.
     * Use it to verify the app can render every action that still needs a user's decision.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithMultipleInterrupts(): array
    {
        return [
            'text' => 'Test',
            'stop_reason' => 'interrupt',
            'interrupts' => [
                [
                    'tool_name' => 'deploy',
                    'tool_input' => ['env' => 'prod'],
                    'tool_use_id' => 'tu-1',
                    'interrupt_id' => 'int-1',
                    'reason' => 'First approval',
                ],
                [
                    'tool_name' => 'scale',
                    'tool_input' => ['count' => 5],
                    'tool_use_id' => 'tu-2',
                    'interrupt_id' => 'int-2',
                    'reason' => 'Second approval',
                ],
            ],
        ];
    }

    /**
     * Verifies response hydration preserves every pending interrupt so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testMultipleInterruptsAllReturned(): void
    {
        $responseData = $this->responsePayloadWithMultipleInterrupts();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(2, $agentResponse->interrupts);
        $this->assertSame('deploy', $agentResponse->interrupts[0]->toolName);
        $this->assertSame('scale', $agentResponse->interrupts[1]->toolName);
    }

    /**
     * Builds a response containing two citations separated by ordinary answer text.
     * Use it to verify the app receives every source link in response order.
     *
     * @return array<string, mixed> Wire response fields consumed by AgentResponse::fromArray(); never empty.
     */
    private function responsePayloadWithMultipleCitations(): array
    {
        return [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'citationsContent', 'source' => 'url1', 'title' => 'Doc 1'],
                    ['type' => 'text', 'text' => 'some text'],
                    ['type' => 'citationsContent', 'source' => 'url2', 'title' => 'Doc 2'],
                ],
            ],
        ];
    }

    /**
     * Verifies response hydration preserves every citation in response order so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testMultipleCitationsAllReturned(): void
    {
        $responseData = $this->responsePayloadWithMultipleCitations();

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertCount(2, $agentResponse->citations);
        $this->assertSame('url1', $agentResponse->citations[0]['source']);
        $this->assertSame('url2', $agentResponse->citations[1]['source']);
    }

    /**
     * Verifies citation hydration returns an empty list when message data is malformed so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testCitationsReturnsEmptyWhenMessageNotArray(): void
    {
        $responseData = [
            'text' => 'Test',
            'message' => 'not an array',
        ];

        $agentResponse = AgentResponse::fromArray($responseData);

        $this->assertSame([], $agentResponse->citations);
    }

    /**
     * Verifies getCitationObjects() returns typed Citation objects so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsTypedList(): void
    {
        $responseData = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    [
                        'type' => 'citationsContent',
                        'location' => ['type' => 'WEB', 'url' => 'https://example.com'],
                        'source_content' => ['text' => 'source text'],
                    ],
                ],
            ],
        ];

        $agentResponse = AgentResponse::fromArray($responseData);
        $citations = $agentResponse->getCitationObjects();

        $this->assertCount(1, $citations);
        $this->assertInstanceOf(Citation::class, $citations[0]);
        $this->assertSame('WEB', $citations[0]->location?->type);
        $this->assertSame('source text', $citations[0]->sourceContent?->text);
    }

    /**
     * Verifies getCitationObjects() preserves flat citation fields so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testGetCitationObjectsPreservesFlatCitationFields(): void
    {
        $responseData = $this->loadJsonFixture('invoke-response-with-citations.json');

        $agentResponse = AgentResponse::fromArray($responseData);
        $citations = $agentResponse->getCitationObjects();

        $this->assertCount(1, $citations);
        $this->assertSame('https://example.com/docs', $citations[0]->source);
        $this->assertSame('Official Documentation', $citations[0]->title);
        $this->assertSame('the answer is 42', $citations[0]->text);
        $this->assertSame('https://example.com/docs', $citations[0]->location?->url);
        $this->assertSame('the answer is 42', $citations[0]->sourceContent?->text);
    }

    /**
     * Verifies getCitationObjects() reuses its hydrated result so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testGetCitationObjectsCachesResult(): void
    {
        $responseData = [
            'text' => 'Test',
            'message' => [
                'content' => [
                    ['type' => 'citationsContent', 'location' => ['type' => 'WEB']],
                ],
            ],
        ];

        $agentResponse = AgentResponse::fromArray($responseData);
        $first = $agentResponse->getCitationObjects();
        $second = $agentResponse->getCitationObjects();

        $this->assertSame($first, $second);
    }

    /**
     * Verifies getCitationObjects() returns an empty list when no citations arrive so callers can safely read results from 1.x agents.
     *
     * @return void
     */
    public function testGetCitationObjectsReturnsEmptyForNoCitations(): void
    {
        $agentResponse = AgentResponse::fromArray(['text' => 'Test']);

        $this->assertSame([], $agentResponse->getCitationObjects());
    }
}

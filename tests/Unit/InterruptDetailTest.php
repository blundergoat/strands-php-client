<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\InterruptDetail;

/**
 * Verifies interrupt details hydrate defensively and produce the resume input needed after a user decision.
 *
 * Use these tests when changing interrupt identifiers, messages, reasons, or resume serialization.
 * They protect approval and clarification flows from losing the action being resumed.
 */
class InterruptDetailTest extends TestCase
{
    /**
     * Builds a complete interrupt payload for an approval or clarification prompt.
     *
     * @return array<string, mixed> Wire fields supplied to InterruptDetail::fromArray(); never empty.
     */
    private function completeInterruptPayload(): array
    {
        return [
            'tool_name' => 'deploy',
            'tool_input' => ['environment' => 'production'],
            'tool_use_id' => 'tu-001',
            'interrupt_id' => 'int-abc',
            'reason' => 'Requires approval',
        ];
    }

    /**
     * Confirms fromArray() hydrates all fields so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $interruptData = $this->completeInterruptPayload();

        $interruptDetail = InterruptDetail::fromArray($interruptData);

        $this->assertSame('deploy', $interruptDetail->toolName);
        $this->assertSame(['environment' => 'production'], $interruptDetail->toolInput);
        $this->assertSame('tu-001', $interruptDetail->toolUseId);
        $this->assertSame('int-abc', $interruptDetail->interruptId);
        $this->assertSame('Requires approval', $interruptDetail->reason);
    }

    /**
     * Confirms fromArray() handles missing fields so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $interruptDetail = InterruptDetail::fromArray([]);

        $this->assertSame('', $interruptDetail->toolName);
        $this->assertSame([], $interruptDetail->toolInput);
        $this->assertNull($interruptDetail->toolUseId);
        $this->assertNull($interruptDetail->interruptId);
        $this->assertNull($interruptDetail->reason);
    }
    /**
     * Builds malformed interrupt fields like those a loosely typed endpoint could return.
     *
     * @return array<string, mixed> Invalid wire values supplied to defensive parsing; never empty.
     */
    private function malformedInterruptPayload(): array
    {
        return [
            'tool_name' => 123,
            'tool_input' => 'not_array',
            'tool_use_id' => 456,
            'interrupt_id' => true,
            'reason' => [],
        ];
    }


    /**
     * Confirms fromArray() rejects non-string fields so malformed responses cannot corrupt approval flows.
     *
     * @return void
     */
    public function testFromArrayHandlesNonStringValues(): void
    {
        $interruptData = $this->malformedInterruptPayload();

        $interruptDetail = InterruptDetail::fromArray($interruptData);

        $this->assertSame('', $interruptDetail->toolName);
        $this->assertSame([], $interruptDetail->toolInput);
        $this->assertNull($interruptDetail->toolUseId);
        $this->assertNull($interruptDetail->interruptId);
        $this->assertNull($interruptDetail->reason);
    }

    /**
     * Confirms callers can instantiate the DTO directly so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testConstructorDirectInstantiation(): void
    {
        $interruptDetail = new InterruptDetail(
            toolName: 'review',
            toolInput: ['pr' => 42],
            interruptId: 'int-999',
        );

        $this->assertSame('review', $interruptDetail->toolName);
        $this->assertSame(['pr' => 42], $interruptDetail->toolInput);
        $this->assertSame('int-999', $interruptDetail->interruptId);
        $this->assertNull($interruptDetail->toolUseId);
        $this->assertNull($interruptDetail->reason);
    }

    /**
     * Confirms toResumeInput() uses interrupt ID so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testToResumeInputUsesInterruptId(): void
    {
        $interruptDetail = new InterruptDetail(
            toolName: 'deploy',
            interruptId: 'int-abc-123',
            toolUseId: 'tu-001',
        );

        $input = $interruptDetail->toResumeInput('Approved');
        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('interrupt_response', $payload['content'][0]['type']);
        $this->assertSame('int-abc-123', $payload['content'][0]['interrupt_id']);
        $this->assertSame('Approved', $payload['content'][0]['response']);
    }

    /**
     * Confirms toResumeInput() falls back to tool use ID so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testToResumeInputFallsBackToToolUseId(): void
    {
        $interruptDetail = new InterruptDetail(
            toolName: 'deploy',
            toolUseId: 'tu-001',
        );

        $input = $interruptDetail->toResumeInput(['action' => 'allow']);
        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('tu-001', $payload['content'][0]['interrupt_id']);
        $this->assertSame(['action' => 'allow'], $payload['content'][0]['response']);
    }

    /**
     * Confirms toResumeInput() throws when no identifier so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testToResumeInputThrowsWhenNoIdentifier(): void
    {
        $interruptDetail = new InterruptDetail(
            toolName: 'deploy',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('neither interruptId nor toolUseId');

        $interruptDetail->toResumeInput('Approved');
    }

    /**
     * Confirms fromArray() with neither ID produces detail so approval flows can safely inspect or resume an action.
     *
     * @return void
     */
    public function testFromArrayWithNeitherIdProducesDetail(): void
    {
        // fromArray() itself should not throw - only toResumeInput() should
        $interruptDetail = InterruptDetail::fromArray(['tool_name' => 'deploy']);

        $this->assertSame('deploy', $interruptDetail->toolName);
        $this->assertNull($interruptDetail->interruptId);
        $this->assertNull($interruptDetail->toolUseId);
    }
}

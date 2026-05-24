<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\InterruptDetail;

class InterruptDetailTest extends TestCase
{
    /**
     * Verifies that from array hydrates all fields.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $data = [
            'tool_name' => 'deploy',
            'tool_input' => ['environment' => 'production'],
            'tool_use_id' => 'tu-001',
            'interrupt_id' => 'int-abc',
            'reason' => 'Requires approval',
        ];

        $detail = InterruptDetail::fromArray($data);

        $this->assertSame('deploy', $detail->toolName);
        $this->assertSame(['environment' => 'production'], $detail->toolInput);
        $this->assertSame('tu-001', $detail->toolUseId);
        $this->assertSame('int-abc', $detail->interruptId);
        $this->assertSame('Requires approval', $detail->reason);
    }

    /**
     * Verifies that from array handles missing fields.
     *
     * @return void
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $detail = InterruptDetail::fromArray([]);

        $this->assertSame('', $detail->toolName);
        $this->assertSame([], $detail->toolInput);
        $this->assertNull($detail->toolUseId);
        $this->assertNull($detail->interruptId);
        $this->assertNull($detail->reason);
    }

    /**
     * Verifies that from array handles non string values.
     *
     * @return void
     */
    public function testFromArrayHandlesNonStringValues(): void
    {
        $data = [
            'tool_name' => 123,
            'tool_input' => 'not_array',
            'tool_use_id' => 456,
            'interrupt_id' => true,
            'reason' => [],
        ];

        $detail = InterruptDetail::fromArray($data);

        $this->assertSame('', $detail->toolName);
        $this->assertSame([], $detail->toolInput);
        $this->assertNull($detail->toolUseId);
        $this->assertNull($detail->interruptId);
        $this->assertNull($detail->reason);
    }

    /**
     * Verifies that constructor direct instantiation.
     *
     * @return void
     */
    public function testConstructorDirectInstantiation(): void
    {
        $detail = new InterruptDetail(
            toolName: 'review',
            toolInput: ['pr' => 42],
            interruptId: 'int-999',
        );

        $this->assertSame('review', $detail->toolName);
        $this->assertSame(['pr' => 42], $detail->toolInput);
        $this->assertSame('int-999', $detail->interruptId);
        $this->assertNull($detail->toolUseId);
        $this->assertNull($detail->reason);
    }

    /**
     * Verifies that to resume input uses interrupt ID.
     *
     * @return void
     */
    public function testToResumeInputUsesInterruptId(): void
    {
        $detail = new InterruptDetail(
            toolName: 'deploy',
            interruptId: 'int-abc-123',
            toolUseId: 'tu-001',
        );

        $input = $detail->toResumeInput('Approved');
        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('interrupt_response', $payload['content'][0]['type']);
        $this->assertSame('int-abc-123', $payload['content'][0]['interrupt_id']);
        $this->assertSame('Approved', $payload['content'][0]['response']);
    }

    /**
     * Verifies that to resume input falls back to tool use ID.
     *
     * @return void
     */
    public function testToResumeInputFallsBackToToolUseId(): void
    {
        $detail = new InterruptDetail(
            toolName: 'deploy',
            toolUseId: 'tu-001',
        );

        $input = $detail->toResumeInput(['action' => 'allow']);
        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('tu-001', $payload['content'][0]['interrupt_id']);
        $this->assertSame(['action' => 'allow'], $payload['content'][0]['response']);
    }

    /**
     * Verifies that to resume input throws when no identifier.
     *
     * @return void
     */
    public function testToResumeInputThrowsWhenNoIdentifier(): void
    {
        $detail = new InterruptDetail(
            toolName: 'deploy',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('neither interruptId nor toolUseId');

        $detail->toResumeInput('Approved');
    }

    /**
     * Verifies that from array with neither ID produces detail.
     *
     * @return void
     */
    public function testFromArrayWithNeitherIdProducesDetail(): void
    {
        // fromArray() itself should not throw - only toResumeInput() should
        $detail = InterruptDetail::fromArray(['tool_name' => 'deploy']);

        $this->assertSame('deploy', $detail->toolName);
        $this->assertNull($detail->interruptId);
        $this->assertNull($detail->toolUseId);
    }
}

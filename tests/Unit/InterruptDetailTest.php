<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Interrupt Detail behavior for app integrations.
 *
 * Use this file when changing Interrupt Detail or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\InterruptDetail;

/**
 * Exercises Interrupt Detail through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class InterruptDetailTest extends TestCase
{
    /**
     * Data fixture for testFromArrayHydratesAllFields().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayHydratesAllFields(): array
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
     * Confirms fromArray() hydrates all fields so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayHydratesAllFields(): void
    {
        $interruptData = $this->dataForFromArrayHydratesAllFields();

        $interruptDetail = InterruptDetail::fromArray($interruptData);

        $this->assertSame('deploy', $interruptDetail->toolName);
        $this->assertSame(['environment' => 'production'], $interruptDetail->toolInput);
        $this->assertSame('tu-001', $interruptDetail->toolUseId);
        $this->assertSame('int-abc', $interruptDetail->interruptId);
        $this->assertSame('Requires approval', $interruptDetail->reason);
    }

    /**
     * Confirms fromArray() handles missing fields so the app renders trustworthy answer details.
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
     * Data fixture for testFromArrayHandlesNonStringValues().
     *
     * @return array<string, mixed> Scenario values; an empty array means this case has no fixture data.
     */
    private function dataForFromArrayHandlesNonStringValues(): array
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
     * Confirms fromArray() handles non string values so the app renders trustworthy answer details.
     *
     * @return void
     */
    public function testFromArrayHandlesNonStringValues(): void
    {
        $interruptData = $this->dataForFromArrayHandlesNonStringValues();

        $interruptDetail = InterruptDetail::fromArray($interruptData);

        $this->assertSame('', $interruptDetail->toolName);
        $this->assertSame([], $interruptDetail->toolInput);
        $this->assertNull($interruptDetail->toolUseId);
        $this->assertNull($interruptDetail->interruptId);
        $this->assertNull($interruptDetail->reason);
    }

    /**
     * Confirms callers can instantiate the DTO directly so the app renders trustworthy answer details.
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
     * Confirms toResumeInput() uses interrupt ID so the app renders trustworthy answer details.
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
     * Confirms toResumeInput() falls back to tool use ID so the app renders trustworthy answer details.
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
     * Confirms toResumeInput() throws when no identifier so the app renders trustworthy answer details.
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
     * Confirms fromArray() with neither ID produces detail so the app renders trustworthy answer details.
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

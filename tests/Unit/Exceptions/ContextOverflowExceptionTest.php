<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;

/**
 * Verifies context-overflow failures remain catchable through both their specific and shared agent-error types.
 *
 * Use these tests when changing the exception hierarchy or context-limit error handling.
 * They protect applications that offer users a shorter or fresh conversation after overflow.
 */
class ContextOverflowExceptionTest extends TestCase
{
    /**
     * Confirms context overflow extends AgentErrorException so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testExtendsAgentErrorException(): void
    {
        $contextOverflowException = new ContextOverflowException('Context too large', statusCode: 400, errorCode: 'context_window_overflow');

        $this->assertInstanceOf(AgentErrorException::class, $contextOverflowException);
        $this->assertSame(400, $contextOverflowException->statusCode);
        $this->assertSame('context_window_overflow', $contextOverflowException->errorCode);
    }

    /**
     * Confirms a parent catch handles context overflow so the app can show or recover from the right failure.
     *
     * @return void
     * @throws AgentErrorException When the context overflow catch-path is exercised.
     */
    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new ContextOverflowException('Overflow', statusCode: 400);
        } catch (AgentErrorException $contextOverflowException) {
            // For example, an app-wide agent error handler can catch context overflow and offer to start a shorter conversation.
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

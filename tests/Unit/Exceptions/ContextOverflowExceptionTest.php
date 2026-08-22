<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Context Overflow Exception behavior for app integrations.
 *
 * Use this file when changing Context Overflow Exception or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;

/**
 * Exercises Context Overflow Exception through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
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

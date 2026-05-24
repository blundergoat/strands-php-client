<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;

class ContextOverflowExceptionTest extends TestCase
{
    /**
     * Verifies that extends agent error exception.
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
     * Verifies that caught by agent error exception catch.
     *
     * @return void
     */
    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new ContextOverflowException('Overflow', statusCode: 400);
        } catch (AgentErrorException $contextOverflowException) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

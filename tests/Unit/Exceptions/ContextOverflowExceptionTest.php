<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;

class ContextOverflowExceptionTest extends TestCase
{
    public function testExtendsAgentErrorException(): void
    {
        $e = new ContextOverflowException('Context too large', statusCode: 400, errorCode: 'context_window_overflow');

        $this->assertInstanceOf(AgentErrorException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('context_window_overflow', $e->errorCode);
    }

    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new ContextOverflowException('Overflow', statusCode: 400);
        } catch (AgentErrorException $e) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

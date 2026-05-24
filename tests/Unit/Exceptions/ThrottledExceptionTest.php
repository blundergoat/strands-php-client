<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ThrottledException;

class ThrottledExceptionTest extends TestCase
{
    /**
     * Verifies that extends agent error exception.
     *
     * @return void
     */
    public function testExtendsAgentErrorException(): void
    {
        $e = new ThrottledException('Rate limited', statusCode: 429);

        $this->assertInstanceOf(AgentErrorException::class, $e);
        $this->assertSame(429, $e->statusCode);
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
            throw new ThrottledException('Rate limited', statusCode: 429);
        } catch (AgentErrorException $e) {
            $caught = true;
            $this->assertSame(429, $e->statusCode);
        }

        $this->assertTrue($caught);
    }
}

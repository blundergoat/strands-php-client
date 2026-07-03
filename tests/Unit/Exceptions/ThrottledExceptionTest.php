<?php

declare(strict_types=1);

/**
 * Tests caller-visible Throttled Exception behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ThrottledException;

/**
 * Verifies Throttled Exception behavior that application users rely on.
 */
class ThrottledExceptionTest extends TestCase
{
    /**
     * Verifies that extends agent error exception.
     *
     * @return void
     */
    public function testExtendsAgentErrorException(): void
    {
        $throttledException = new ThrottledException('Rate limited', statusCode: 429);

        $this->assertInstanceOf(AgentErrorException::class, $throttledException);
        $this->assertSame(429, $throttledException->statusCode);
    }

    /**
     * Verifies that caught by agent error exception catch.
     *
     * @return void
     * @throws AgentErrorException When the throttling catch-path is exercised.
     */
    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new ThrottledException('Rate limited', statusCode: 429);
        } catch (AgentErrorException $throttledException) {
            $caught = true;
            $this->assertSame(429, $throttledException->statusCode);
        }

        $this->assertTrue($caught);
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ThrottledException;

/**
 * Verifies rate-limit failures retain their HTTP details and remain catchable through the shared agent-error type.
 *
 * Use these tests when changing the exception hierarchy or throttling response handling.
 * They protect applications that tell users when and why an agent request must be retried.
 */
class ThrottledExceptionTest extends TestCase
{
    /**
     * Confirms throttling extends AgentErrorException so the app can show or recover from the right failure.
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
     * Confirms a parent catch handles throttling so the app can show or recover from the right failure.
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
            // For example, an app-wide agent error handler can catch rate limiting and ask the user to retry later.
            $caught = true;
            $this->assertSame(429, $throttledException->statusCode);
        }

        $this->assertTrue($caught);
    }
}

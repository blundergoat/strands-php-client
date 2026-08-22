<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Throttled Exception behavior for app integrations.
 *
 * Use this file when changing Throttled Exception or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ThrottledException;

/**
 * Exercises Throttled Exception through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
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

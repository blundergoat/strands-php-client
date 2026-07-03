<?php

declare(strict_types=1);

/**
 * Tests caller-visible Max Tokens Exception behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\MaxTokensException;

/**
 * Verifies Max Tokens Exception behavior that application users rely on.
 */
class MaxTokensExceptionTest extends TestCase
{
    /**
     * Verifies that extends agent error exception.
     *
     * @return void
     */
    public function testExtendsAgentErrorException(): void
    {
        $maxTokensException = new MaxTokensException('Max tokens reached', statusCode: 400, errorCode: 'max_tokens_reached');

        $this->assertInstanceOf(AgentErrorException::class, $maxTokensException);
        $this->assertSame(400, $maxTokensException->statusCode);
        $this->assertSame('max_tokens_reached', $maxTokensException->errorCode);
    }

    /**
     * Verifies that caught by agent error exception catch.
     *
     * @return void
     * @throws AgentErrorException When the max-token catch-path is exercised.
     */
    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new MaxTokensException('Max tokens', statusCode: 400);
        } catch (AgentErrorException $maxTokensException) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

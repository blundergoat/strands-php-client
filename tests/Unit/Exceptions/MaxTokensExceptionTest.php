<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\MaxTokensException;

/**
 * Verifies token-limit failures remain catchable through both their specific and shared agent-error types.
 *
 * Use these tests when changing the exception hierarchy or maximum-token error handling.
 * They protect applications that offer users a continuation or shorter answer after truncation.
 */
class MaxTokensExceptionTest extends TestCase
{
    /**
     * Confirms MaxTokensException extends AgentErrorException so the app can handle all agent failures consistently.
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
     * Confirms a parent catch handles max tokens so the app can show or recover from the right failure.
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
            // For example, an app-wide agent error handler can catch a truncated answer and offer the user a continue action.
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

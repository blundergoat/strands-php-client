<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Max Tokens Exception behavior for app integrations.
 *
 * Use this file when changing Max Tokens Exception or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\MaxTokensException;

/**
 * Exercises Max Tokens Exception through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
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

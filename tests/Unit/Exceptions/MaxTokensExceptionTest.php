<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\MaxTokensException;

class MaxTokensExceptionTest extends TestCase
{
    public function testExtendsAgentErrorException(): void
    {
        $e = new MaxTokensException('Max tokens reached', statusCode: 400, errorCode: 'max_tokens_reached');

        $this->assertInstanceOf(AgentErrorException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('max_tokens_reached', $e->errorCode);
    }

    public function testCaughtByAgentErrorExceptionCatch(): void
    {
        $caught = false;

        try {
            throw new MaxTokensException('Max tokens', statusCode: 400);
        } catch (AgentErrorException $e) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}

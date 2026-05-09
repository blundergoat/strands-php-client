<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;
use StrandsPhpClient\Exceptions\MaxTokensException;
use StrandsPhpClient\Exceptions\ThrottledException;

class AgentErrorExceptionTest extends TestCase
{
    public function testFromHttpResponseReturnsThrottledFor429(): void
    {
        $e = AgentErrorException::fromHttpResponse(429, 'Rate limited', ['detail' => 'Too many requests']);

        $this->assertInstanceOf(ThrottledException::class, $e);
        $this->assertSame(429, $e->statusCode);
    }

    public function testFromHttpResponseReturnsContextOverflow(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'overflow', ['detail' => 'context too large', 'code' => 'context_window_overflow']);

        $this->assertInstanceOf(ContextOverflowException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('context_window_overflow', $e->errorCode);
    }

    public function testFromHttpResponseReturnsMaxTokens(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'tokens', ['detail' => 'limit reached', 'code' => 'max_tokens_reached']);

        $this->assertInstanceOf(MaxTokensException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('max_tokens_reached', $e->errorCode);
    }

    public function testFromHttpResponseReturnsGenericForOtherErrors(): void
    {
        $e = AgentErrorException::fromHttpResponse(500, 'Internal error', ['detail' => 'Something broke']);

        $this->assertInstanceOf(AgentErrorException::class, $e);
        $this->assertNotInstanceOf(ThrottledException::class, $e);
        $this->assertNotInstanceOf(ContextOverflowException::class, $e);
        $this->assertNotInstanceOf(MaxTokensException::class, $e);
        $this->assertSame(500, $e->statusCode);
    }

    public function testFromHttpResponseContextOverflowCaseInsensitive(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'error_code' => 'Context_Window_Overflow']);

        $this->assertInstanceOf(ContextOverflowException::class, $e);
    }

    public function testFromHttpResponseMaxTokensVariant(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'code' => 'MAX_TOKENS_EXCEEDED']);

        $this->assertInstanceOf(MaxTokensException::class, $e);
    }

    public function testAllSubclassesCaughtByParent(): void
    {
        $exceptions = [
            new ThrottledException('test', statusCode: 429),
            new ContextOverflowException('test', statusCode: 400),
            new MaxTokensException('test', statusCode: 400),
        ];

        foreach ($exceptions as $e) {
            $caught = false;

            try {
                throw $e;
            } catch (AgentErrorException) {
                $caught = true;
            }

            $this->assertTrue($caught, sprintf('%s not caught by AgentErrorException', $e::class));
        }
    }
}

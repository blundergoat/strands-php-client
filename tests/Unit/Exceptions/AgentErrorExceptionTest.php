<?php

declare(strict_types=1);

/**
 * Tests caller-visible Agent Error Exception behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;
use StrandsPhpClient\Exceptions\MaxTokensException;
use StrandsPhpClient\Exceptions\ThrottledException;

/**
 * Verifies Agent Error Exception behavior that application users rely on.
 */
class AgentErrorExceptionTest extends TestCase
{
    /**
     * Verifies that from HTTP response returns throttled for 429.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsThrottledForTooManyRequests(): void
    {
        $e = AgentErrorException::fromHttpResponse(429, 'Rate limited', ['detail' => 'Too many requests']);

        $this->assertInstanceOf(ThrottledException::class, $e);
        $this->assertSame(429, $e->statusCode);
    }

    /**
     * Verifies that from HTTP response returns context overflow.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsContextOverflow(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'overflow', ['detail' => 'context too large', 'code' => 'context_window_overflow']);

        $this->assertInstanceOf(ContextOverflowException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('context_window_overflow', $e->errorCode);
    }

    /**
     * Verifies that from HTTP response returns max tokens.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsMaxTokens(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'tokens', ['detail' => 'limit reached', 'code' => 'max_tokens_reached']);

        $this->assertInstanceOf(MaxTokensException::class, $e);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame('max_tokens_reached', $e->errorCode);
    }

    /**
     * Verifies that from HTTP response returns generic for other errors.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsGenericForOtherErrors(): void
    {
        $e = AgentErrorException::fromHttpResponse(500, 'Internal error', ['detail' => 'Something broke']);

        $this->assertInstanceOf(AgentErrorException::class, $e);
        $this->assertNotInstanceOf(ThrottledException::class, $e);
        $this->assertNotInstanceOf(ContextOverflowException::class, $e);
        $this->assertNotInstanceOf(MaxTokensException::class, $e);
        $this->assertSame(500, $e->statusCode);
    }

    /**
     * Verifies that from HTTP response context overflow case insensitive.
     *
     * @return void
     */
    public function testFromHttpResponseContextOverflowCaseInsensitive(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'error_code' => 'Context_Window_Overflow']);

        $this->assertInstanceOf(ContextOverflowException::class, $e);
    }

    /**
     * Verifies that from HTTP response max tokens variant.
     *
     * @return void
     */
    public function testFromHttpResponseMaxTokensVariant(): void
    {
        $e = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'code' => 'MAX_TOKENS_EXCEEDED']);

        $this->assertInstanceOf(MaxTokensException::class, $e);
    }
    /**
     * Data fixture for testAllSubclassesCaughtByParent().
     *
     * @return array<string, mixed> Exception subclasses that app code can catch through the parent type.
     */
    private function dataForAllSubclassesCaughtByParent(): array
    {
        return [
            new ThrottledException('test', statusCode: 429),
            new ContextOverflowException('test', statusCode: 400),
            new MaxTokensException('test', statusCode: 400),
        ];
    }


    /**
     * Verifies that all subclasses caught by parent.
     *
     * @return void
     * @throws AgentErrorException When the subclass catch-path is exercised.
     */
    public function testAllSubclassesCaughtByParent(): void
    {
        $exceptions = $this->dataForAllSubclassesCaughtByParent();

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

    /**
     * Verifies that the wire contract's human-readable message wins over structured detail.
     *
     * @return void
     */
    public function testFromHttpResponsePrefersContractMessageOverStructuredDetail(): void
    {
        // Mirrors tests/Fixtures/wire-contract/error-response.json: the wrapper
        // sends a human-readable "message" plus a structured "detail" object.
        $e = AgentErrorException::fromHttpResponse(
            400,
            '{"message":"Validation failed.","code":"validation_error","detail":{"field":"message"}}',
            ['message' => 'Validation failed.', 'code' => 'validation_error', 'detail' => ['field' => 'message']],
        );

        $this->assertSame('Agent returned HTTP 400: Validation failed.', $e->getMessage());
        $this->assertSame('validation_error', $e->errorCode);
        $this->assertSame(['field' => 'message'], $e->responseBody['detail'] ?? null);
    }

    /**
     * Verifies that an empty message falls back to the detail field.
     *
     * @return void
     */
    public function testFromHttpResponseFallsBackToDetailWhenMessageEmpty(): void
    {
        $e = AgentErrorException::fromHttpResponse(
            502,
            '{"message":"","detail":"Upstream agent unavailable"}',
            ['message' => '', 'detail' => 'Upstream agent unavailable'],
        );

        $this->assertSame('Agent returned HTTP 502: Upstream agent unavailable', $e->getMessage());
    }
}

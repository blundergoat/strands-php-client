<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Exceptions\ContextOverflowException;
use StrandsPhpClient\Exceptions\MaxTokensException;
use StrandsPhpClient\Exceptions\ThrottledException;

/**
 * Verifies HTTP agent failures become the documented typed exception with the most useful available message.
 *
 * Use these tests when changing status mapping, structured error parsing, or exception subclasses.
 * They protect the recovery choices and diagnostic detail available to calling applications.
 */
class AgentErrorExceptionTest extends TestCase
{
    /**
     * Confirms fromHttpResponse() returns throttled for 429 so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsThrottledForTooManyRequests(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(429, 'Rate limited', ['detail' => 'Too many requests']);

        $this->assertInstanceOf(ThrottledException::class, $agentErrorException);
        $this->assertSame(429, $agentErrorException->statusCode);
    }

    /**
     * Confirms fromHttpResponse() returns context overflow so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsContextOverflow(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(
            400,
            'overflow',
            ['detail' => 'context too large', 'code' => 'context_window_overflow'],
        );

        $this->assertInstanceOf(ContextOverflowException::class, $agentErrorException);
        $this->assertSame(400, $agentErrorException->statusCode);
        $this->assertSame('context_window_overflow', $agentErrorException->errorCode);
    }

    /**
     * Confirms fromHttpResponse() returns max tokens so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsMaxTokens(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(400, 'tokens', ['detail' => 'limit reached', 'code' => 'max_tokens_reached']);

        $this->assertInstanceOf(MaxTokensException::class, $agentErrorException);
        $this->assertSame(400, $agentErrorException->statusCode);
        $this->assertSame('max_tokens_reached', $agentErrorException->errorCode);
    }

    /**
     * Confirms fromHttpResponse() returns generic for other errors so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseReturnsGenericForOtherErrors(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(500, 'Internal error', ['detail' => 'Something broke']);

        $this->assertInstanceOf(AgentErrorException::class, $agentErrorException);
        $this->assertNotInstanceOf(ThrottledException::class, $agentErrorException);
        $this->assertNotInstanceOf(ContextOverflowException::class, $agentErrorException);
        $this->assertNotInstanceOf(MaxTokensException::class, $agentErrorException);
        $this->assertSame(500, $agentErrorException->statusCode);
    }

    /**
     * Confirms fromHttpResponse() context overflow case insensitive so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseContextOverflowCaseInsensitive(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'error_code' => 'Context_Window_Overflow']);

        $this->assertInstanceOf(ContextOverflowException::class, $agentErrorException);
    }

    /**
     * Confirms fromHttpResponse() max tokens variant so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseMaxTokensVariant(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(400, 'err', ['detail' => 'err', 'code' => 'MAX_TOKENS_EXCEEDED']);

        $this->assertInstanceOf(MaxTokensException::class, $agentErrorException);
    }
    /**
     * Builds the specialized failures that callers may catch through AgentErrorException.
     *
     * @return list<AgentErrorException> Non-empty list of specialized failures catchable through the parent type.
     */
    private function specializedAgentErrors(): array
    {
        return [
            new ThrottledException('test', statusCode: 429),
            new ContextOverflowException('test', statusCode: 400),
            new MaxTokensException('test', statusCode: 400),
        ];
    }


    /**
     * Confirms all subclasses caught by parent so the app can show or recover from the right failure.
     *
     * @return void
     * @throws AgentErrorException When the subclass catch-path is exercised.
     */
    public function testAllSubclassesCaughtByParent(): void
    {
        $exceptions = $this->specializedAgentErrors();

        // Every specialized failure must remain catchable by apps that use only the documented parent exception.
        foreach ($exceptions as $exceptionToCatch) {
            $caught = false;

            try {
                throw $exceptionToCatch;
            } catch (AgentErrorException) {
                // For example, one generic error banner can catch throttling, context overflow, and token-limit failures through the parent type.
                $caught = true;
            }

            $this->assertTrue($caught, sprintf('%s not caught by AgentErrorException', $exceptionToCatch::class));
        }
    }

    /**
     * Confirms the wire contract's human-readable message wins over structured detail so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponsePrefersContractMessageOverStructuredDetail(): void
    {
        // Mirrors tests/Fixtures/wire-contract/error-response.json: the wrapper
        // sends a human-readable "message" plus a structured "detail" object.
        $agentErrorException = AgentErrorException::fromHttpResponse(
            400,
            '{"message":"Validation failed.","code":"validation_error","detail":{"field":"message"}}',
            ['message' => 'Validation failed.', 'code' => 'validation_error', 'detail' => ['field' => 'message']],
        );

        $this->assertSame('Agent returned HTTP 400: Validation failed.', $agentErrorException->getMessage());
        $this->assertSame('validation_error', $agentErrorException->errorCode);
        $this->assertSame(['field' => 'message'], $agentErrorException->responseBody['detail'] ?? null);
    }

    /**
     * Confirms an empty message falls back to the detail field so the app can show or recover from the right failure.
     *
     * @return void
     */
    public function testFromHttpResponseFallsBackToDetailWhenMessageEmpty(): void
    {
        $agentErrorException = AgentErrorException::fromHttpResponse(
            502,
            '{"message":"","detail":"Upstream agent unavailable"}',
            ['message' => '', 'detail' => 'Upstream agent unavailable'],
        );

        $this->assertSame('Agent returned HTTP 502: Upstream agent unavailable', $agentErrorException->getMessage());
    }
}

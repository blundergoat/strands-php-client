<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Exception thrown when the Strands agent returns an HTTP error response (400+).
 *
 * The $statusCode and $errorCode properties enable programmatic handling
 * (e.g. "if errorCode is 'rate_limit', back off"). The $responseBody
 * preserves the full decoded JSON for debugging.
 */
class AgentErrorException extends StrandsException
{
    /**
     * @param string               $message      Human-readable error message.
     * @param int                  $statusCode   HTTP status code from the agent response.
     * @param string|null          $errorCode    Machine-readable error code (e.g. "unauthorized").
     * @param \Throwable|null      $previous     The original exception, if any.
     * @param array<string, mixed>|null $responseBody Full decoded response body for debugging.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Build an AgentErrorException from a raw HTTP error response.
     *
     * @param int    $statusCode  HTTP status code (400+).
     * @param string $content     Raw response body string.
     * @param mixed  $decoded     json_decode() result (array, null, or other scalar).
     */
    public static function fromHttpResponse(int $statusCode, string $content, mixed $decoded): self
    {
        /** @var array<string, mixed> $errorData */
        $errorData = is_array($decoded) ? $decoded : [];
        $detail = $errorData['detail'] ?? $errorData['error'] ?? $content;
        if (is_string($detail)) {
            $detailText = $detail;
        } else {
            $detailText = json_encode($detail) ?: 'Unknown agent error';
        }
        $errorMessage = sprintf('Agent returned HTTP %d: %s', $statusCode, $detailText);

        $rawCode = $errorData['code'] ?? $errorData['error_code'] ?? null;

        return new self(
            message: $errorMessage,
            statusCode: $statusCode,
            errorCode: is_string($rawCode) ? $rawCode : null,
            responseBody: $errorData !== [] ? $errorData : null,
        );
    }
}

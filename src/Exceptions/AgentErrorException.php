<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Exception thrown when the Strands agent returns an HTTP error response (400+).
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
        $errorMessage = is_string($detail) ? $detail : (json_encode($detail) ?: 'Unknown agent error');

        return new self(
            message: $errorMessage,
            statusCode: $statusCode,
            responseBody: $errorData ?: null,
        );
    }
}

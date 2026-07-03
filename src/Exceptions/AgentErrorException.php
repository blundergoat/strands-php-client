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
     * @return self New instance ready for app code.
     */
    public static function fromHttpResponse(int $statusCode, string $content, mixed $decoded): self
    {
        /** @var array<string, mixed> $errorData validated before app code uses it. */
        $errorData = is_array($decoded) ? $decoded : [];
        $detail = $errorData['detail'] ?? $errorData['error'] ?? $content;
        if (is_string($detail)) {
            $detailText = $detail;
        } else {
            $detailText = json_encode($detail) ?: 'Unknown agent error';
        }
        $errorMessage = sprintf('Agent returned HTTP %d: %s', $statusCode, $detailText);

        $rawCode = $errorData['code'] ?? $errorData['error_code'] ?? null;
        $errorCode = is_string($rawCode) ? $rawCode : null;
        $responseBody = $errorData !== [] ? $errorData : null;

        $class = self::resolveExceptionClass($statusCode, $errorCode);

        return new $class(
            message: $errorMessage,
            statusCode: $statusCode,
            errorCode: $errorCode,
            responseBody: $responseBody,
        );
    }

    /**
     * Selects the exception type the app can catch.
     *
     * @param class-string<self> $default Fallback returned when app config is missing.
     *
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param ?string $errorCode Value supplied by app code.
     * @return class-string<self> Value returned to app code.
     */
    private static function resolveExceptionClass(int $statusCode, ?string $errorCode, string $default = self::class): string
    {
        if ($statusCode === 429) {
            return ThrottledException::class;
        }

        if ($errorCode !== null) {
            $lower = strtolower($errorCode);

            if (str_contains($lower, 'context') && str_contains($lower, 'overflow')) {
                return ContextOverflowException::class;
            }

            if (str_contains($lower, 'max_tokens')) {
                return MaxTokensException::class;
            }
        }

        return $default;
    }
}

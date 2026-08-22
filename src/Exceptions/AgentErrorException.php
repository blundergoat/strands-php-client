<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Exception thrown when the Strands agent returns an HTTP error response (400+).
 *
 * Apps can use the status and error code to choose a recovery message or retry behavior.
 * The decoded response body remains available for safe diagnostics when a request fails.
 */
class AgentErrorException extends StrandsException
{
    /**
     * Carry an agent HTTP failure the app can catch and inspect.
     *
     * Usually created by fromHttpResponse() when a call comes back 400+; the app
     * reads $statusCode/$errorCode to decide how to recover.
     *
     * @param string               $message      Human-readable error message.
     * @param int                  $statusCode   HTTP status code from the agent response.
     * @param string|null          $errorCode    Machine-readable error code (e.g. "unauthorized"); null when the agent sent none.
     * @param \Throwable|null $previous Original failure; null means no lower-level error is available to inspect.
     * @param array<string, mixed>|null $responseBody Full decoded response body for debugging; null when the error body wasn't JSON.
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
        $contractMessage = $errorData['message'] ?? null;
        $detail = $errorData['detail'] ?? $errorData['error'] ?? $content;
        // Prefer the Wire Contract message because it is the error text intended for the app.
        if (is_string($contractMessage) && $contractMessage !== '') {
            $detailText = $contractMessage;
        } elseif (
            // A FastAPI-style wrapper may return only plain detail text; show that useful fallback in the app's error state.
            is_string($detail)
        ) {
            $detailText = $detail;
        } else {
            // Structured or missing detail becomes readable fallback text instead of leaking an unusable value into the UI.
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
     * @param class-string<self> $default Exception class to fall back to when no specific subtype matches.
     *
     * @param int $statusCode HTTP status recorded for app diagnostics.
     * @param ?string $errorCode Agent's machine-readable error code, or null when it sent none.
     * @return class-string<self> The most specific exception class for this error, so apps can catch it precisely.
     */
    private static function resolveExceptionClass(int $statusCode, ?string $errorCode, string $default = self::class): string
    {
        // 429 always means rate limiting, whatever the body says — map it directly.
        if ($statusCode === 429) {
            return ThrottledException::class;
        }

        // When the agent named a specific error, pick the matching typed exception so the
        // app can catch (e.g.) a context overflow without string-matching the message.
        if ($errorCode !== null) {
            $lower = strtolower($errorCode);

            // Conversation grew past the model's window — the app should trim or restart it.
            if (str_contains($lower, 'context') && str_contains($lower, 'overflow')) {
                return ContextOverflowException::class;
            }

            // The answer was cut off at the token cap — the app may offer to continue.
            if (str_contains($lower, 'max_tokens')) {
                return MaxTokensException::class;
            }
        }

        return $default;
    }
}

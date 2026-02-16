<?php

declare(strict_types=1);

namespace Strands\Exceptions;

/**
 * Exception thrown when the Strands agent returns an HTTP error response (400+).
 */
class AgentErrorException extends StrandsException
{
    /**
     * @param string          $message    Human-readable error message.
     * @param int             $statusCode HTTP status code from the agent response.
     * @param string|null     $errorCode  Machine-readable error code (e.g. "unauthorized").
     * @param \Throwable|null $previous   The original exception, if any.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

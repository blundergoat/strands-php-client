<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent rejects a turn because the conversation is too long.
 *
 * The user's running chat history plus their latest message no longer fit the
 * model's context window. Apps typically recover by summarising earlier turns
 * or starting a fresh session, then resending the request.
 */
class ContextOverflowException extends AgentErrorException
{
}

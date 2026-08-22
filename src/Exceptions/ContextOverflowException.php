<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent rejects a turn because the conversation is too long.
 *
 * It means the chat history and latest message no longer fit the model's context window.
 * Apps can summarize earlier turns or start a fresh session before retrying the user's request.
 */
class ContextOverflowException extends AgentErrorException
{
}

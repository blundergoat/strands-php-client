<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Thrown when the agent reports that the maximum token limit was reached.
 */
class MaxTokensException extends AgentErrorException
{
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent stops early because it hit the output token cap.
 *
 * It tells the caller that generation stopped at the configured limit rather than completing normally.
 * Use it to offer a continue action or retry with a higher output limit.
 */
class MaxTokensException extends AgentErrorException
{
}

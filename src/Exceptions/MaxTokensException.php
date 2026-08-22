<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent stops early because it hit the output token cap.
 *
 * It tells the app that the visible answer was truncated rather than completed.
 * Use it to offer a continue action or retry with a higher output limit.
 */
class MaxTokensException extends AgentErrorException
{
}

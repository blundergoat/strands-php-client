<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent stops early because it hit the output token cap.
 *
 * The answer the user sees is cut off mid-response rather than finished. Apps
 * usually surface a "response truncated" hint and offer to continue, or raise
 * the max-tokens limit before retrying.
 */
class MaxTokensException extends AgentErrorException
{
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent returns HTTP 429 (Too Many Requests).
 *
 * The app is calling faster than the service currently allows. Apps usually
 * back off and retry after a short wait, or ask the user to slow down. When
 * automatic retries are configured, this surfaces only after they are exhausted.
 */
class ThrottledException extends AgentErrorException
{
}

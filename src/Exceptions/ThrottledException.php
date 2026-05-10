<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Thrown when the agent returns HTTP 429 (Too Many Requests).
 */
class ThrottledException extends AgentErrorException
{
}

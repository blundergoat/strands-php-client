<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when the agent returns HTTP 429 (Too Many Requests).
 *
 * It means the app is calling faster than the service currently allows.
 * Use it to wait and retry or ask the user to slow down after automatic retries are exhausted.
 */
class ThrottledException extends AgentErrorException
{
}

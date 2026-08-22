<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Base type for every error this client can raise.
 *
 * Catch it to give every client failure one app-level fallback, from connection problems to agent errors.
 * Catch a subtype when the UI needs a specific recovery path, such as retrying throttling or shortening a conversation.
 */
class StrandsException extends \RuntimeException
{
}

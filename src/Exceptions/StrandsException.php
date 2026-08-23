<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Base type for failures the client wraps as Strands runtime errors.
 *
 * Catch it for transport, parsing, and agent-response failures represented by this library.
 * Input validation and missing environment configuration can still raise native PHP exceptions.
 */
class StrandsException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when a stream cannot produce a complete terminal result.
 *
 * A timeout, oversized frame, or missing terminal event may leave the caller with partial output.
 * Treat that output as incomplete before deciding whether to retry.
 */
class StreamInterruptedException extends StrandsException
{
}

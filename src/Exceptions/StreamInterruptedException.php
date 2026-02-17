<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Exception thrown when an SSE stream ends without a terminal event.
 *
 * Indicates the stream was interrupted (connection dropped, timeout, etc.)
 * and the response is likely incomplete.
 */
class StreamInterruptedException extends StrandsException
{
}

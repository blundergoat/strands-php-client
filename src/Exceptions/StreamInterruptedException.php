<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when a streaming answer ends before the agent signalled completion.
 *
 * It means a dropped connection, timeout, or safety limit left only a partial answer on screen.
 * Use it to show an interrupted state and let the user retry the message.
 */
class StreamInterruptedException extends StrandsException
{
}

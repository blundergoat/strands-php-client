<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Raised when a streaming answer ends before the agent signalled completion.
 *
 * The user was watching a live response and the connection dropped, timed out,
 * or the safety buffer overflowed — so what is on screen is partial. Apps
 * typically show a "connection lost" state and let the user retry the message.
 */
class StreamInterruptedException extends StrandsException
{
}

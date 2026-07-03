<?php

declare(strict_types=1);

namespace StrandsPhpClient\Exceptions;

/**
 * Base type for every error this client can raise.
 *
 * Catch this in app code to handle any Strands failure in one place — a bad
 * endpoint, a transport problem, or an error reported by the agent all extend
 * from here. Catch a more specific subtype instead when the app needs to react
 * differently to, say, throttling versus a context-window overflow.
 */
class StrandsException extends \RuntimeException
{
}

<?php

/**
 * Facade for the default StrandsClient instance.
 *
 * @method static \Strands\Response\AgentResponse invoke(string $message, ?\Strands\Context\AgentContext $context = null, ?string $sessionId = null)
 * @method static \Strands\Streaming\StreamResult stream(string $message, callable $onEvent, ?\Strands\Context\AgentContext $context = null, ?string $sessionId = null)
 *
 * @see \Strands\StrandsClient
 */

declare(strict_types=1);

namespace Strands\Integration\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Strands\StrandsClient;

class Strands extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StrandsClient::class;
    }
}

<?php

/**
 * Facade for the default StrandsClient instance.
 *
 * @method static \StrandsPhpClient\Response\AgentResponse invoke(string $message, ?\StrandsPhpClient\Context\AgentContext $context = null, ?string $sessionId = null)
 * @method static \StrandsPhpClient\Streaming\StreamResult stream(string $message, callable $onEvent, ?\StrandsPhpClient\Context\AgentContext $context = null, ?string $sessionId = null)
 * @method static array postJson(string $path, array $payload, ?int $timeout = null)
 * @method static void streamSse(string $path, array $payload, callable $onEvent, ?int $timeout = null)
 *
 * @see \StrandsPhpClient\StrandsClient
 */

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use StrandsPhpClient\StrandsClient;

class Strands extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StrandsClient::class;
    }
}

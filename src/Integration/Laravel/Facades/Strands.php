<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use StrandsPhpClient\Context\AgentContext;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\StrandsClient;
use StrandsPhpClient\Streaming\StreamResult;

/**
 * Static, app-friendly entry point to the default Strands client.
 *
 * Laravel code calls Strands::invoke(...) or Strands::stream(...) without resolving the container manually.
 * The facade forwards each user action to the default StrandsClient registered by the service provider.
 *
 * @method static AgentResponse invoke(string $message, ?AgentContext $context = null, ?string $sessionId = null)
 * @method static StreamResult stream(string $message, callable $onEvent, ?AgentContext $context = null, ?string $sessionId = null)
 * @method static array<string, mixed> postJson(string $path, array<string, mixed> $payload, ?int $timeout = null)
 * @method static void streamSse(string $path, array<string, mixed> $payload, callable $onEvent, ?int $timeout = null)
 *
 * @see StrandsClient
 */
class Strands extends Facade
{
    /**
     * Return the container binding resolved by the Laravel facade.
     *
     * @return string Service container key for the default client binding.
     */
    protected static function getFacadeAccessor(): string
    {
        return StrandsClient::class;
    }
}

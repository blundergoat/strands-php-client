<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony;

use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers the Strands client as a Symfony bundle.
 *
 * Symfony discovers this bundle at boot and loads its dependency-injection
 * extension, which reads the app's `strands` config and wires up named agent
 * clients so controllers and services can inject a ready-to-use client.
 */
class StrandsBundle extends Bundle
{
    /**
     * Hand Symfony the extension that turns app config into wired services.
     *
     * Called once while the container is compiled; without it the bundle's
     * config and service definitions would never be registered.
     *
     * @return ExtensionInterface|null The DI extension that loads Strands services.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new StrandsExtension();
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony;

use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers the Strands client as a Symfony bundle.
 *
 * Symfony discovers it at boot and loads the extension that reads the app's `strands` configuration.
 * Use the resulting named services when controllers or services need a ready agent client.
 */
class StrandsBundle extends Bundle
{
    /**
     * Hand Symfony the extension that turns app config into wired services.
     *
     * Called once while the container is compiled; without it the bundle's
     * config and service definitions would never be registered.
     *
     * @return ExtensionInterface|null The DI extension that loads Strands services; this bundle never returns null.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new StrandsExtension();
    }
}

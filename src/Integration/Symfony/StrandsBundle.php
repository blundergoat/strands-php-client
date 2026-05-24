<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony;

use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for the Strands PHP Client.
 */
class StrandsBundle extends Bundle
{
    /**
     * Create the Symfony dependency-injection extension for this bundle.
     *
     * @return ExtensionInterface|null Symfony extension instance for this bundle.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new StrandsExtension();
    }
}

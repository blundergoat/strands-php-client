<?php

declare(strict_types=1);

namespace Strands\Integration\Symfony;

use Strands\Integration\Symfony\DependencyInjection\StrandsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for the Strands PHP Client.
 */
class StrandsBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new StrandsExtension();
    }
}

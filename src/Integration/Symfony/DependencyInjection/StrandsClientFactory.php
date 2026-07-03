<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony\DependencyInjection;

use StrandsPhpClient\Integration\StrandsClientFactory as BaseFactory;

/**
 * Keeps Symfony service wiring aligned with the shared client factory.
 *
 * All logic lives in the shared base class. This subclass preserves the
 * original namespace so existing Symfony service definitions continue to work.
 */
class StrandsClientFactory extends BaseFactory
{
}

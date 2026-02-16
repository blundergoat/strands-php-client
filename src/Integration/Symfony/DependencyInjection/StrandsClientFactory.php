<?php

/**
 * Symfony-specific factory subclass for backward compatibility.
 *
 * All logic lives in the shared base class. This subclass preserves the
 * original namespace so existing Symfony service definitions continue to work.
 */

declare(strict_types=1);

namespace Strands\Integration\Symfony\DependencyInjection;

use Strands\Integration\StrandsClientFactory as BaseFactory;

class StrandsClientFactory extends BaseFactory
{
}

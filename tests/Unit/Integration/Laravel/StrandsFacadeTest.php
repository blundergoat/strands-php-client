<?php

declare(strict_types=1);

/**
 * Tests caller-visible Strands Facade behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Laravel\Facades\Strands;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies Strands Facade behavior that application users rely on.
 */
class StrandsFacadeTest extends TestCase
{
    /**
     * Verifies that facade accessor returns strands client class.
     *
     * @return void
     */
    public function testFacadeAccessorReturnsStrandsClientClass(): void
    {
        $testFacade = new class () extends Strands {
            /**
             * Expose the Laravel binding key that app code resolves through the facade.
             *
             * @return string container binding used by the facade.
             */
            public static function accessor(): string
            {
                return parent::getFacadeAccessor();
            }
        };

        $this->assertSame(StrandsClient::class, $testFacade::accessor());
    }
}

<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Laravel\Facades\Strands;
use StrandsPhpClient\StrandsClient;

/**
 * Verifies the Laravel facade resolves the same StrandsClient binding application code requests from the container.
 *
 * Use this test when changing the facade accessor or Laravel service name.
 * It protects controller and command code that calls an agent through the facade.
 */
class StrandsFacadeTest extends TestCase
{
    /**
     * Confirms facade accessor returns strands client class so Laravel callers resolve the configured client.
     *
     * @return void
     */
    public function testFacadeAccessorReturnsStrandsClientClass(): void
    {
        $testFacade = new class () extends Strands {
            /**
             * Expose the Laravel binding key that app code resolves through the facade.
             *
             * @return string Container binding used by the facade; never empty.
             */
            public static function accessor(): string
            {
                return parent::getFacadeAccessor();
            }
        };

        $this->assertSame(StrandsClient::class, $testFacade::accessor());
    }
}

<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Strands Facade behavior for app integrations.
 *
 * Use this file when changing Strands Facade or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Laravel\Facades\Strands;
use StrandsPhpClient\StrandsClient;

/**
 * Exercises Strands Facade through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
class StrandsFacadeTest extends TestCase
{
    /**
     * Confirms facade accessor returns strands client class so framework users receive a correctly configured client.
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

<?php

declare(strict_types=1);

namespace Strands\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;
use Strands\Integration\Laravel\Facades\Strands;
use Strands\StrandsClient;

class StrandsFacadeTest extends TestCase
{
    public function testFacadeAccessorReturnsStrandsClientClass(): void
    {
        $reflection = new \ReflectionMethod(Strands::class, 'getFacadeAccessor');

        $this->assertSame(StrandsClient::class, $reflection->invoke(null));
    }
}

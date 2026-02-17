<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Laravel;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Integration\Laravel\Facades\Strands;
use StrandsPhpClient\StrandsClient;

class StrandsFacadeTest extends TestCase
{
    public function testFacadeAccessorReturnsStrandsClientClass(): void
    {
        $reflection = new \ReflectionMethod(Strands::class, 'getFacadeAccessor');

        $this->assertSame(StrandsClient::class, $reflection->invoke(null));
    }
}

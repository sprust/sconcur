<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\Cpu;

class CpuTest extends TestCase
{
    public function testReturnsAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, Cpu::count());
    }
}

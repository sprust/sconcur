<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SConcur\Connection\Extension;
use SConcur\Tests\Impl\TestApplication;

abstract class BaseTestCase extends TestCase
{
    protected Extension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        TestApplication::init();

        $this->extension = Extension::get();
        $this->extension->destroy();
    }

    protected function tearDown(): void
    {
        $this->assertNoTasksCount();

        parent::tearDown();
    }

    protected function assertNoTasksCount(): void
    {
        $startTime = time();

        while (true) {
            if ($this->extension->count() === 0) {
                return;
            }

            if ((time() - $startTime) < 2) {
                continue;
            }

            break;
        }

        self::assertEquals(
            0,
            $this->extension->count()
        );
    }
}

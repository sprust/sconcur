<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features;

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

        $this->extension = new Extension();
        $this->extension->destroy();
    }

    protected function assertNoTasksCount(): void
    {
        self::assertEquals(
            0,
            $this->extension->count()
        );
    }
}

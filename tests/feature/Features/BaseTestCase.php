<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\TestContainer;

abstract class BaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestContainer::flush();
        TestContainer::resolve();
    }
}

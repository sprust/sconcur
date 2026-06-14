<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Tests\Feature\BaseTestCase;

class WaitAnyTimeoutTest extends BaseTestCase
{
    public function testReturnsNullWhenNothingIsReadyWithinTheTimeout(): void
    {
        $start = microtime(true);

        $result = $this->extension->waitAnyTimeout(50);

        $elapsed = microtime(true) - $start;

        self::assertNull($result, 'an idle extension must time out, not block or return a result');
        self::assertGreaterThanOrEqual(0.045, $elapsed, 'it should have waited about the requested timeout');
        self::assertLessThan(1.0, $elapsed, 'it must not block far past the timeout');
    }
}

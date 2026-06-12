<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use ReflectionMethod;
use SConcur\Connection\Extension;
use SConcur\Exceptions\ExtensionCallException;
use SConcur\Tests\Feature\BaseTestCase;
use function SConcur\Extension\push;
use function SConcur\Extension\stopFlow;

/**
 * A failed push used to be silently ignored: the fiber suspended waiting for
 * a task that was never started, blocking the process forever.
 */
class ExtensionCallErrorTest extends BaseTestCase
{
    public function testPushWithUnknownMethodReturnsErrorAndLeavesNoTasks(): void
    {
        $flowKey = uniqid();

        $response = push($flowKey, 99, 'task-1', 'payload');

        self::assertStringStartsWith('error:', $response);

        self::assertEquals(0, $this->extension->count());

        stopFlow($flowKey);
    }

    public function testErrorResponseThrowsExtensionCallException(): void
    {
        $checkCallResponse = new ReflectionMethod(Extension::class, 'checkCallResponse');

        $this->expectException(ExtensionCallException::class);

        $checkCallResponse->invoke(null, 'flow', 'error: push: unknown method: 99');
    }

    public function testSuccessResponsePasses(): void
    {
        $checkCallResponse = new ReflectionMethod(Extension::class, 'checkCallResponse');

        $checkCallResponse->invoke(null, 'flow', '');

        $this->expectNotToPerformAssertions();
    }
}

<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SConcur\Features\MethodEnum;
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

        $response = push($flowKey, 'nope', 'task-1', ['payload' => true], 0);

        self::assertStringStartsWith('error:', $response);

        self::assertEquals(0, $this->extension->count());

        stopFlow($flowKey);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refusedValues(): array
    {
        return [
            'closure'  => [static fn(): int => 1, 'Closure'],
            'enum'     => [MethodEnum::Sleep, 'enum case'],
            'resource' => [STDIN, 'resource'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function testPushRefusesValueWithNoReadableState(mixed $value, string $named): void
    {
        $flowKey = uniqid();

        $response = push($flowKey, 'nope', 'task-1', ['nested' => ['value' => $value]], 0);

        self::assertStringStartsWith('error: push: ', $response);
        self::assertStringContainsString($named, $response);
        self::assertEquals(0, $this->extension->count());

        stopFlow($flowKey);
    }

    public function testErrorResponseThrowsExtensionCallException(): void
    {
        $checkCallResponse = new ReflectionMethod(Extension::class, 'checkCallResponse');

        $this->expectException(ExtensionCallException::class);

        $checkCallResponse->invoke(null, 'flow', 'error: push: unknown method: nope');
    }

    public function testSuccessResponsePasses(): void
    {
        $checkCallResponse = new ReflectionMethod(Extension::class, 'checkCallResponse');

        $checkCallResponse->invoke(null, 'flow', '');

        $this->expectNotToPerformAssertions();
    }
}

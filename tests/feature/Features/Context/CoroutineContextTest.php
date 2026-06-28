<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Context;

use ReflectionProperty;
use SConcur\Context\Context;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\State;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * Framework-neutral acceptance tests for the per-coroutine context: isolation
 * between concurrent coroutines, survival across await, read-through inheritance,
 * local-only writes (shadowing without leaking), cleanup on completion, and the
 * root context outside any fiber. Mirrors .ai/plans/sconcur-coroutine-context §9.
 */
class CoroutineContextTest extends BaseTestCase
{
    public function testIsolationSurvivesAwait(): void
    {
        $group = WaitGroup::create();

        // Sleeps long enough that the sibling sets its own 'k' and returns while
        // this one is suspended; on resume it must still read its own value.
        $group->add(static function (): string {
            Context::current()->set('k', 'A');

            Sleeper::usleep(microseconds: 40_000);

            return (string) Context::current()->find('k');
        });

        $group->add(static function (): string {
            Context::current()->set('k', 'B');

            Sleeper::usleep(microseconds: 10_000);

            return (string) Context::current()->find('k');
        });

        $results = array_values($group->waitResults());

        sort($results);

        self::assertSame(['A', 'B'], $results);
    }

    public function testChildInheritsParentContext(): void
    {
        $outer = WaitGroup::create();

        $outer->add(static function (): mixed {
            Context::current()->set('req', 'X');

            $inner = WaitGroup::create();

            $inner->add(static function (): mixed {
                Sleeper::usleep(microseconds: 10_000);

                return Context::current()->find('req');
            });

            return array_values($inner->waitResults())[0];
        });

        self::assertSame('X', array_values($outer->waitResults())[0]);
    }

    public function testChildShadowsWithoutLeaking(): void
    {
        $outer = WaitGroup::create();

        $outer->add(static function (): array {
            $context = Context::current();

            $context->set('req', 'X');

            $inner = WaitGroup::create();

            $shadowingKey = $inner->add(static function (): mixed {
                Context::current()->set('req', 'Y');

                Sleeper::usleep(microseconds: 10_000);

                return Context::current()->find('req');
            });

            $siblingKey = $inner->add(static function (): mixed {
                Sleeper::usleep(microseconds: 20_000);

                return Context::current()->find('req');
            });

            $results = $inner->waitResults();

            return [
                'shadowing' => $results[$shadowingKey],
                'sibling'   => $results[$siblingKey],
                'parent'    => $context->find('req'),
            ];
        });

        /** @var array{shadowing: mixed, sibling: mixed, parent: mixed} $result */
        $result = array_values($outer->waitResults())[0];

        self::assertSame('Y', $result['shadowing']);
        self::assertSame('X', $result['sibling']);
        self::assertSame('X', $result['parent']);
    }

    public function testReplaceSemantics(): void
    {
        $key     = 'replace_' . uniqid();
        $context = Context::current();

        $context->set($key, 'first');
        $context->set($key, 'second');

        self::assertSame('first', $context->find($key), 'set without replace must keep the existing value');

        $context->set($key, 'third', replace: true);

        self::assertSame('third', $context->find($key));

        $context->forget($key);
    }

    public function testOutsideFiberUsesRootContext(): void
    {
        $key         = 'outside_' . uniqid();
        $nullKey     = 'outside_null_' . uniqid();
        $rootContext = Context::current();

        self::assertFalse($rootContext->has($key));
        self::assertNull($rootContext->find($key));

        $rootContext->set($key, 'root-value');

        self::assertTrue($rootContext->has($key));
        self::assertSame('root-value', $rootContext->find($key));

        // A stored null still counts as present (has() is by key, not by value).
        $rootContext->set($nullKey, null);

        self::assertTrue($rootContext->has($nullKey));
        self::assertNull($rootContext->find($nullKey));

        $rootContext->forget($key);
        $rootContext->forget($nullKey);

        self::assertFalse($rootContext->has($key));
    }

    public function testContextReleasedOnCompletion(): void
    {
        $baselineContext = $this->stateArraySize('fiberContext');
        $baselineParent  = $this->stateArraySize('fiberContextParent');

        $group = WaitGroup::create();

        for ($index = 0; $index < 5; $index++) {
            $group->add(static function () use ($index): void {
                Context::current()->set('index', $index);

                Sleeper::usleep(microseconds: 5_000);
            });
        }

        $group->waitAll();

        // No per-coroutine context (own map or parent link) may outlive its
        // coroutine: the internal stores return to their pre-run size.
        self::assertSame($baselineContext, $this->stateArraySize('fiberContext'));
        self::assertSame($baselineParent, $this->stateArraySize('fiberContextParent'));
    }

    private function stateArraySize(string $property): int
    {
        $reflectionProperty = new ReflectionProperty(State::class, $property);

        /** @var array<int, mixed> $value */
        $value = $reflectionProperty->getValue();

        return count($value);
    }
}

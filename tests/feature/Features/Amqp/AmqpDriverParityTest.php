<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The calque in SConcur\Features\Amqp must expose exactly the API ext-amqp does, so that
 * moving an application over is a change of `use` lines and nothing else.
 *
 * The comparison is made against the installed extension rather than against literals
 * written down once: the promise kept here is parity with the extension pinned in
 * composer.json, and a version bump that changes a signature or a constant must fail this
 * test rather than pass unnoticed.
 */
class AmqpDriverParityTest extends TestCase
{
    /** The namespace the calque lives in; stripped when types are compared. */
    private const string NAMESPACE_PREFIX = 'SConcur\\Features\\Amqp\\';

    /**
     * The public methods the calque adds. Each one is a deliberate, documented difference
     * (docs/amqp.md), and listing them here is what keeps the list from growing quietly.
     */
    private const array ALLOWED_EXTRA_METHODS = [
        // PHP does not tell the Go side about garbage collection, so a dropped channel
        // closes itself; ext-amqp frees its channel resource the same way, in C.
        'AMQPChannel'    => ['__destruct'],
        'AMQPConnection' => ['__destruct'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('amqp')) {
            self::markTestSkipped('ext-amqp is not installed, there is nothing to compare against.');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function classesProvider(): array
    {
        $classes = [
            'AMQPConnection',
            'AMQPChannel',
            'AMQPExchange',
            'AMQPQueue',
            'AMQPEnvelope',
            'AMQPBasicProperties',
            'AMQPDecimal',
            'AMQPTimestamp',
            'AMQPValue',
            'AMQPException',
            'AMQPConnectionException',
            'AMQPChannelException',
            'AMQPQueueException',
            'AMQPExchangeException',
            'AMQPEnvelopeException',
            'AMQPValueException',
        ];

        $provided = [];

        foreach ($classes as $class) {
            $provided[$class] = [$class];
        }

        return $provided;
    }

    #[DataProvider('classesProvider')]
    public function testTheCalqueDeclaresTheSamePublicMethods(string $class): void
    {
        $extension = new ReflectionClass($class);
        $calque    = new ReflectionClass(self::NAMESPACE_PREFIX . $class);

        $extra = array_diff(
            $this->declaredMethodNames($calque),
            $this->declaredMethodNames($extension),
        );

        self::assertSame(
            [],
            array_values(array_diff($extra, self::ALLOWED_EXTRA_METHODS[$class] ?? [])),
            "$class declares public methods ext-amqp does not",
        );

        self::assertSame(
            [],
            array_values(array_diff(
                $this->declaredMethodNames($extension),
                $this->declaredMethodNames($calque),
            )),
            "$class is missing public methods ext-amqp declares",
        );
    }

    #[DataProvider('classesProvider')]
    public function testTheCalqueDeclaresTheSameSignatures(string $class): void
    {
        $extension = new ReflectionClass($class);
        $calque    = new ReflectionClass(self::NAMESPACE_PREFIX . $class);

        $expected = [];
        $actual   = [];

        foreach ($extension->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $name = $method->getName();

            $expected[$name] = $this->signature($method);
            $actual[$name]   = $this->signature($calque->getMethod($name));
        }

        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual, "$class: method signatures");
    }

    #[DataProvider('classesProvider')]
    public function testTheCalqueKeepsTheSameClassShape(string $class): void
    {
        $extension = new ReflectionClass($class);
        $calque    = new ReflectionClass(self::NAMESPACE_PREFIX . $class);

        self::assertSame($extension->isInterface(), $calque->isInterface(), "$class: interface or class");
        self::assertSame($extension->isFinal(), $calque->isFinal(), "$class: final");
        self::assertSame($extension->isReadOnly(), $calque->isReadOnly(), "$class: readonly");

        self::assertSame(
            $this->publicConstants($extension),
            $this->publicConstants($calque),
            "$class: public class constants",
        );
    }

    public function testTheCalqueKeepsTheExceptionHierarchy(): void
    {
        $exceptions = [
            'AMQPConnectionException',
            'AMQPChannelException',
            'AMQPQueueException',
            'AMQPExchangeException',
            'AMQPEnvelopeException',
            'AMQPValueException',
        ];

        foreach ($exceptions as $exception) {
            $calque = self::NAMESPACE_PREFIX . $exception;

            self::assertSame(
                self::NAMESPACE_PREFIX . 'AMQPException',
                get_parent_class($calque),
                "$exception must extend AMQPException, as it does in ext-amqp",
            );
        }

        // The one deliberate difference: the base extends RuntimeException instead of
        // Exception, following the project's rule for runtime failures. Every ext-amqp
        // catch block still matches, since RuntimeException is itself an Exception.
        self::assertSame('RuntimeException', get_parent_class(self::NAMESPACE_PREFIX . 'AMQPException'));
    }

    public function testTheCalqueDeclaresTheSameConstants(): void
    {
        $extensionConstants = get_defined_constants(true)['amqp'] ?? [];

        self::assertNotSame([], $extensionConstants, 'ext-amqp defines no constants');

        foreach ($extensionConstants as $name => $value) {
            $ours = self::NAMESPACE_PREFIX . $name;

            self::assertTrue(defined($ours), "the calque does not define $name");
            self::assertSame($value, constant($ours), "$name does not match ext-amqp");
        }
    }

    /**
     * The public constants of a class, name-sorted so the two sides compare regardless of
     * declaration order. The internal ones of the calque are protected and stay out of
     * this by construction.
     *
     * @param ReflectionClass<object> $class
     *
     * @return array<string, mixed>
     */
    private function publicConstants(ReflectionClass $class): array
    {
        $constants = [];

        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $value = $constant->getValue();

            $constants[$constant->getName()] = is_string($value)
                ? $this->stripNamespaceFromString($value)
                : $value;
        }

        ksort($constants);

        return $constants;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function declaredMethodNames(ReflectionClass $class): array
    {
        $names = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $names[] = $method->getName();
        }

        sort($names);

        return $names;
    }

    /**
     * The comparable form of a method: its parameters (name, type, default) and its
     * return type, with the calque's namespace stripped so a type hint on AMQPChannel
     * compares equal to the extension's.
     */
    private function signature(ReflectionMethod $method): string
    {
        $parameters = array_map(
            fn(ReflectionParameter $parameter): string => sprintf(
                '%s $%s%s',
                $parameter->hasType() ? $this->typeName($parameter) : 'mixed',
                $parameter->getName(),
                $parameter->isDefaultValueAvailable()
                    ? ' = ' . var_export($parameter->getDefaultValue(), true)
                    : '',
            ),
            $method->getParameters(),
        );

        $returnType = $method->getReturnType();

        return sprintf(
            '(%s): %s',
            implode(', ', $parameters),
            $returnType === null ? 'mixed' : $this->stripNamespaceFromString((string) $returnType),
        );
    }

    private function typeName(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType) {
            return $this->stripNamespaceFromString((string) $type);
        }

        return $this->stripNamespaceFromString((string) $type);
    }

    private function stripNamespaceFromString(string $value): string
    {
        return str_replace(self::NAMESPACE_PREFIX, '', $value);
    }
}

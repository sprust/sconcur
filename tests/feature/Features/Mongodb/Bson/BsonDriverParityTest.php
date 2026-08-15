<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Bson;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\Binary as DriverBinary;
use MongoDB\BSON\Decimal128 as DriverDecimal128;
use MongoDB\BSON\Int64 as DriverInt64;
use MongoDB\BSON\Javascript as DriverJavascript;
use MongoDB\BSON\MaxKey as DriverMaxKey;
use MongoDB\BSON\MinKey as DriverMinKey;
use MongoDB\BSON\ObjectId as DriverObjectId;
use MongoDB\BSON\Regex as DriverRegex;
use MongoDB\BSON\Timestamp as DriverTimestamp;
use MongoDB\BSON\UTCDateTime as DriverUTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SConcur\Bson\Binary;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
use SConcur\Bson\Decimal128;
use SConcur\Bson\Int64;
use SConcur\Bson\Javascript;
use SConcur\Bson\MaxKey;
use SConcur\Bson\MinKey;
use SConcur\Bson\ObjectId;
use SConcur\Bson\Regex;
use SConcur\Bson\Timestamp;
use SConcur\Bson\UTCDateTime;
use Stringable;

/**
 * The SConcur BSON value objects must behave exactly like the ext-mongodb ones,
 * so that moving an application over is a change of `use` lines and nothing else.
 *
 * These tests compare the two side by side rather than asserting literals: a
 * literal would drift the day the driver changes, and the promise being kept here
 * is parity with the driver, not with a value written down once.
 */
class BsonDriverParityTest extends TestCase
{
    /** @return array<string, array{0: object, 1: object}> */
    public static function pairsProvider(): array
    {
        return [
            'ObjectId' => [
                new DriverObjectId('6919e3d1a3673d3f4d9137a3'),
                new ObjectId('6919e3d1a3673d3f4d9137a3'),
            ],
            'UTCDateTime' => [
                new DriverUTCDateTime(1_700_000_000_123),
                new UTCDateTime(1_700_000_000_123),
            ],
            'Binary' => [
                new DriverBinary('binary-data', DriverBinary::TYPE_GENERIC),
                new Binary('binary-data', Binary::TYPE_GENERIC),
            ],
            'Regex' => [
                new DriverRegex('^abc', 'ix'),
                new Regex('^abc', 'ix'),
            ],
            'Timestamp' => [
                new DriverTimestamp(1, 1_700_000_000),
                new Timestamp(1, 1_700_000_000),
            ],
            'Decimal128' => [
                new DriverDecimal128('3.14159'),
                new Decimal128('3.14159'),
            ],
            'Javascript' => [
                new DriverJavascript('function () { return 1; }', ['a' => 1]),
                new Javascript('function () { return 1; }', ['a' => 1]),
            ],
            'MinKey' => [new DriverMinKey(), new MinKey()],
            'MaxKey' => [new DriverMaxKey(), new MaxKey()],
            'Int64'  => [new DriverInt64('9000000000'), new Int64('9000000000')],
        ];
    }

    #[DataProvider('pairsProvider')]
    public function testStringFormMatchesTheDriver(object $driver, object $sconcur): void
    {
        if (!$driver instanceof Stringable) {
            self::assertFalse(
                $sconcur instanceof Stringable,
                'the driver value is not Stringable, so ours must not be either',
            );

            return;
        }

        self::assertInstanceOf(Stringable::class, $sconcur);
        self::assertSame((string) $driver, (string) $sconcur);
    }

    #[DataProvider('pairsProvider')]
    public function testJsonFormMatchesTheDriver(object $driver, object $sconcur): void
    {
        self::assertSame(json_encode($driver), json_encode($sconcur));
    }

    /**
     * Every public getter the driver exposes must exist here and answer the same,
     * which is what makes the swap invisible to calling code.
     */
    #[DataProvider('pairsProvider')]
    public function testGettersMatchTheDriver(object $driver, object $sconcur): void
    {
        $getters = array_filter(
            get_class_methods($driver),
            static fn(string $method): bool => str_starts_with($method, 'get'),
        );

        $ourGetters = array_filter(
            get_class_methods($sconcur),
            static fn(string $method): bool => str_starts_with($method, 'get'),
        );

        // Neither side may invent a getter the other does not have, or the two
        // stop being interchangeable. Declaration order is not part of that.
        $expected = array_values($getters);
        $actual   = array_values($ourGetters);

        sort($expected);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            sprintf('%s exposes a different set of getters', $sconcur::class),
        );

        foreach ($getters as $getter) {
            self::assertTrue(
                method_exists($sconcur, $getter),
                sprintf('%s::%s() is missing', $sconcur::class, $getter),
            );

            self::assertEquals(
                $driver->$getter(),
                $sconcur->$getter(),
                sprintf('%s::%s() differs from the driver', $sconcur::class, $getter),
            );
        }
    }

    public function testUTCDateTimeConversionsMatchTheDriver(): void
    {
        $driver  = new DriverUTCDateTime(1_700_000_000_123);
        $sconcur = new UTCDateTime(1_700_000_000_123);

        self::assertSame(
            $driver->toDateTime()->format('Y-m-d H:i:s.u'),
            $sconcur->toDateTime()->format('Y-m-d H:i:s.u'),
        );

        self::assertSame(
            $driver->toDateTimeImmutable()->format('Y-m-d H:i:s.u'),
            $sconcur->toDateTimeImmutable()->format('Y-m-d H:i:s.u'),
        );
    }

    public function testUTCDateTimeAcceptsTheSameArgumentsAsTheDriver(): void
    {
        $dateTime = new DateTimeImmutable('2026-06-12 06:44:55.250000', new DateTimeZone('UTC'));

        self::assertSame(
            (string) new DriverUTCDateTime($dateTime),
            (string) new UTCDateTime($dateTime),
        );

        self::assertSame(
            (string) new DriverUTCDateTime(new DriverInt64('1700000000123')),
            (string) new UTCDateTime(new Int64('1700000000123')),
        );
    }

    public function testGeneratedObjectIdLooksLikeTheDriverOne(): void
    {
        $sconcur = new ObjectId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $sconcur);
        self::assertEqualsWithDelta(time(), $sconcur->getTimestamp(), 5.0);

        // Two ids taken in a row must differ, or a bulk insert would collide.
        self::assertNotSame((string) new ObjectId(), (string) new ObjectId());
    }

    public function testInvalidObjectIdIsRejectedLikeTheDriver(): void
    {
        $this->expectException(InvalidBsonValueException::class);

        new ObjectId('not-an-object-id');
    }
}

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
use SConcur\Bson\Decimal128;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
use SConcur\Bson\Int64;
use SConcur\Bson\Javascript;
use SConcur\Bson\MaxKey;
use SConcur\Bson\MinKey;
use SConcur\Bson\ObjectId;
use SConcur\Bson\Regex;
use SConcur\Bson\Timestamp;
use SConcur\Bson\UTCDateTime;
use Stringable;
use Throwable;

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

    /**
     * The five random bytes are drawn once per process, as the driver draws them,
     * so that the counter is what separates two ids taken inside the same second.
     * Rerolling them per id would leave the order of a batch to chance, and sorting
     * by _id is how insertion order is normally read back.
     */
    public function testGeneratedObjectIdsShareTheProcessValue(): void
    {
        $ids = [];

        for ($index = 0; $index < 5; $index++) {
            $ids[] = (string) new ObjectId();
        }

        $processValues = array_unique(
            array_map(static fn(string $id): string => substr($id, 8, 10), $ids),
        );

        self::assertCount(1, $processValues, 'the process value must be the same for every id');
        self::assertCount(5, array_unique($ids), 'the counter must make every id distinct');
    }

    /**
     * @return array<string, array{0: callable(): object, 1: callable(): object}>
     */
    public static function rejectedValuesProvider(): array
    {
        return [
            'Timestamp increment below zero' => [
                static fn(): object => new DriverTimestamp(-1, 0),
                static fn(): object => new Timestamp(-1, 0),
            ],
            'Timestamp seconds above 32 bits' => [
                static fn(): object => new DriverTimestamp(0, 4_294_967_296),
                static fn(): object => new Timestamp(0, 4_294_967_296),
            ],
            'Int64 that is not a number' => [
                static fn(): object => new DriverInt64('abc'),
                static fn(): object => new Int64('abc'),
            ],
            'Int64 above 64 bits' => [
                static fn(): object => new DriverInt64('9223372036854775808'),
                static fn(): object => new Int64('9223372036854775808'),
            ],
            'Int64 that is not a whole number' => [
                static fn(): object => new DriverInt64('12.5'),
                static fn(): object => new Int64('12.5'),
            ],
            'Binary subtype above one byte' => [
                static fn(): object => new DriverBinary('x', 300),
                static fn(): object => new Binary('x', 300),
            ],
        ];
    }

    /**
     * The driver takes the halves of a Timestamp as strings too, and parses them
     * the same way. Its stub types them as int, so this case cannot ride in the
     * pair provider above.
     */
    public function testTimestampRejectsAStringThatIsNotANumber(): void
    {
        $this->expectException(InvalidBsonValueException::class);

        new Timestamp('a', 0);
    }

    /**
     * A cast in place of the driver's validation turns a bad value into a plausible
     * one — PHP clamps an overflowing numeric string to PHP_INT_MAX and reads a
     * non-numeric one as 0 — and the collection would take it.
     *
     * @param callable(): object $driver
     * @param callable(): object $sconcur
     */
    #[DataProvider('rejectedValuesProvider')]
    public function testRejectsTheSameValuesAsTheDriver(callable $driver, callable $sconcur): void
    {
        $driverRejected = false;

        try {
            $driver();
        } catch (Throwable) {
            $driverRejected = true;
        }

        self::assertTrue($driverRejected, 'the driver accepts this value, so the case pins the wrong behaviour');

        $this->expectException(InvalidBsonValueException::class);

        $sconcur();
    }
}

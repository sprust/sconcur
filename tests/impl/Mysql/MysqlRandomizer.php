<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql;

readonly class MysqlRandomizer
{
    public static function randStr(int $len): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len);
    }

    public static function randInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public static function randFloat(): float
    {
        return (float) (self::randInt(0, 999999) / 100);
    }

    public static function varchar(): string
    {
        return self::randStr(100);
    }

    public static function char(): string
    {
        return self::randStr(50);
    }

    public static function text(): string
    {
        return self::randStr(500);
    }

    public static function tinytext(): string
    {
        return self::randStr(50);
    }

    public static function mediumtext(): string
    {
        return self::randStr(1000);
    }

    public static function longtext(): string
    {
        return self::randStr(2000);
    }

    public static function tinyint(): int
    {
        return self::randInt(-128, 127);
    }

    public static function smallint(): int
    {
        return self::randInt(-32768, 32767);
    }

    public static function mediumint(): int
    {
        return self::randInt(-8388608, 8388607);
    }

    public static function int(): int
    {
        return self::randInt(-2147483648, 2147483647);
    }

    public static function bigint(): int
    {
        return self::randInt(-922337203685477, 922337203685477);
    }

    public static function decimal(): float
    {
        return self::randFloat();
    }

    public static function numeric(): float
    {
        return self::randFloat();
    }

    public static function float(): float
    {
        return self::randFloat();
    }

    public static function double(): float
    {
        return self::randFloat();
    }

    public static function bit(): int
    {
        return self::randInt(0, 255);
    }

    public static function date(): string
    {
        return date('Y-m-d', self::randInt(0, time()));
    }

    public static function datetime(): string
    {
        return date('Y-m-d H:i:s', self::randInt(0, time()));
    }

    public static function timestamp(): string
    {
        return date('Y-m-d H:i:s', self::randInt(0, time()));
    }

    public static function time(): string
    {
        return sprintf('%02d:%02d:%02d', self::randInt(0, 23), self::randInt(0, 59), self::randInt(0, 59));
    }

    public static function year(): int
    {
        return self::randInt(1901, 2155);
    }

    public static function binary(): string
    {
        return random_bytes(16);
    }

    public static function varbinary(): string
    {
        return random_bytes(self::randInt(1, 100));
    }

    public static function blob(): string
    {
        return random_bytes(self::randInt(1, 100));
    }

    public static function tinyblob(): string
    {
        return random_bytes(self::randInt(1, 50));
    }

    public static function mediumblob(): string
    {
        return random_bytes(self::randInt(1, 200));
    }

    public static function longblob(): string
    {
        return random_bytes(self::randInt(1, 500));
    }

    public static function enum(): string
    {
        return ['value1', 'value2', 'value3'][self::randInt(0, 2)];
    }

    public static function set(): string
    {
        return implode(',', array_slice(['option1', 'option2', 'option3'], 0, self::randInt(1, 3)));
    }

    public static function json(): string
    {
        return json_encode(['key' => self::randStr(20), 'value' => self::randInt(1, 1000)]);
    }

    public static function bool(): int
    {
        return self::randInt(0, 1);
    }
}

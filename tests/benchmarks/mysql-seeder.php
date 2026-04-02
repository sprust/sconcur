<?php

declare(strict_types=1);

use Random\RandomException;
use SConcur\Features\Mysql\Serialization\BindingSerializer;
use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-create-table',
);

TestMysqlResolver::dropSconcurTable();
TestMysqlResolver::initSconcurTable();

$sconcurClient = TestMysqlResolver::getSconcurTestClient();

$benchmarker->run(
    syncCallback: static function () use ($sconcurClient) {
        $query = makeInsertOneQuery();

        return $sconcurClient->exec($query[0], $query[1])->rowsAffected;
    },
    asyncCallback: static function () use ($sconcurClient) {
        $query = makeInsertOneQuery();

        return $sconcurClient->exec($query[0], $query[1])->rowsAffected;
    }
);

/**
 * @return array{0: string, 1: array<int, mixed>}
 *
 * @throws RandomException
 */
function makeInsertOneQuery(): array
{
    $database = TestMysqlResolver::getTestTableName();

    $randStr = fn(int $len) => substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len);
    $randInt = fn(int $min, int $max) => random_int($min, $max);
    $randFloat = fn() => (float) ($randInt(0, 999999) / 100);

    $varchar_col = $randStr(100);
    $char_col = $randStr(50);
    $text_col = $randStr(500);
    $tinytext_col = $randStr(50);
    $mediumtext_col = $randStr(1000);
    $longtext_col = $randStr(2000);
    $tinyint_col = $randInt(-128, 127);
    $smallint_col = $randInt(-32768, 32767);
    $mediumint_col = $randInt(-8388608, 8388607);
    $int_col = $randInt(-2147483648, 2147483647);
    $bigint_col = $randInt(-922337203685477, 922337203685477);
    $decimal_col = $randFloat();
    $numeric_col = $randFloat();
    $float_col = $randFloat();
    $double_col = $randFloat();
    $bit_col = $randInt(0, 255);
    $date_col = date('Y-m-d', $randInt(0, time()));
    $datetime_col = date('Y-m-d H:i:s', $randInt(0, time()));
    $timestamp_col = date('Y-m-d H:i:s', $randInt(0, time()));
    $time_col = sprintf('%02d:%02d:%02d', $randInt(0, 23), $randInt(0, 59), $randInt(0, 59));
    $year_col = $randInt(1901, 2155);
    $binary_col = BindingSerializer::bin(random_bytes(16));
    $varbinary_col = BindingSerializer::bin(random_bytes($randInt(1, 100)));
    $blob_col = BindingSerializer::bin(random_bytes($randInt(1, 100)));
    $tinyblob_col = BindingSerializer::bin(random_bytes($randInt(1, 50)));
    $mediumblob_col = BindingSerializer::bin(random_bytes($randInt(1, 200)));
    $longblob_col = BindingSerializer::bin(random_bytes($randInt(1, 500)));
    $enum_col = ['value1', 'value2', 'value3'][$randInt(0, 2)];
    $set_col = implode(',', array_slice(['option1', 'option2', 'option3'], 0, $randInt(1, 3)));
    $json_col = json_encode(['key' => $randStr(20), 'value' => $randInt(1, 1000)]);
    $bool_col = $randInt(0, 1);

    $sql = "INSERT INTO $database (varchar_col, char_col, text_col, tinytext_col, mediumtext_col, longtext_col, " .
        "tinyint_col, smallint_col, mediumint_col, int_col, bigint_col, decimal_col, numeric_col, float_col, double_col, " .
        "bit_col, date_col, datetime_col, timestamp_col, time_col, year_col, binary_col, varbinary_col, blob_col, " .
        "tinyblob_col, mediumblob_col, longblob_col, enum_col, set_col, json_col, bool_col) VALUES " .
        "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $bindings = [
        $varchar_col,
        $char_col,
        $text_col,
        $tinytext_col,
        $mediumtext_col,
        $longtext_col,
        $tinyint_col,
        $smallint_col,
        $mediumint_col,
        $int_col,
        $bigint_col,
        $decimal_col,
        $numeric_col,
        $float_col,
        $double_col,
        $bit_col,
        $date_col,
        $datetime_col,
        $timestamp_col,
        $time_col,
        $year_col,
        $binary_col,
        $varbinary_col,
        $blob_col,
        $tinyblob_col,
        $mediumblob_col,
        $longblob_col,
        $enum_col,
        $set_col,
        $json_col,
        $bool_col
    ];

    return [$sql, $bindings];
}
<?php

declare(strict_types=1);

use SConcur\Tests\Impl\Mysql\Dto\TestMysqlDto;
use SConcur\Tests\Impl\Mysql\MysqlRandomizer;
use SConcur\Tests\Impl\Mysql\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-seeder',
);

$driverRepository = TestMysqlResolver::getDriverRepository();
$sconcurRepository = TestMysqlResolver::getSconcurRepository();

$sconcurRepository->dropTableIfExists();
$sconcurRepository->createTableIfNotExists();

$sconcurClient = TestMysqlResolver::getSconcurRepository();

$benchmarker->run(
    nativeCallback: static function () use ($driverRepository) {
        return $driverRepository->insert(
            makeTestMysqlDto()
        );
    },
    syncCallback: static function () use ($sconcurRepository) {
        return $sconcurRepository->insert(
            makeTestMysqlDto()
        );
    },
    asyncCallback: static function () use ($sconcurRepository) {
        return $sconcurRepository->insert(
            makeTestMysqlDto()
        );
    }
);

function makeTestMysqlDto(): TestMysqlDto
{
    return new TestMysqlDto(
        varcharCol: MysqlRandomizer::varchar(),
        charCol: MysqlRandomizer::char(),
        textCol: MysqlRandomizer::text(),
        tinytextCol: MysqlRandomizer::tinytext(),
        mediumtextCol: MysqlRandomizer::mediumtext(),
        longtextCol: MysqlRandomizer::longtext(),
        tinyintCol: MysqlRandomizer::tinyint(),
        smallintCol: MysqlRandomizer::smallint(),
        mediumintCol: MysqlRandomizer::mediumint(),
        intCol: MysqlRandomizer::int(),
        bigintCol: MysqlRandomizer::bigint(),
        decimalCol: MysqlRandomizer::decimal(),
        numericCol: MysqlRandomizer::numeric(),
        floatCol: MysqlRandomizer::float(),
        doubleCol: MysqlRandomizer::double(),
        bitCol: MysqlRandomizer::bit(),
        dateCol: MysqlRandomizer::date(),
        datetimeCol: MysqlRandomizer::datetime(),
        timestampCol: MysqlRandomizer::timestamp(),
        timeCol: MysqlRandomizer::time(),
        yearCol: MysqlRandomizer::year(),
        binaryCol: MysqlRandomizer::binary(),
        varbinaryCol: MysqlRandomizer::varbinary(),
        blobCol: MysqlRandomizer::blob(),
        tinyblobCol: MysqlRandomizer::tinyblob(),
        mediumblobCol: MysqlRandomizer::mediumblob(),
        longblobCol: MysqlRandomizer::longblob(),
        enumCol: MysqlRandomizer::enum(),
        setCol: MysqlRandomizer::set(),
        jsonCol: MysqlRandomizer::json(),
        boolCol: MysqlRandomizer::bool(),
    );
}
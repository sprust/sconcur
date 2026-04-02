<?php

declare(strict_types=1);

use SConcur\Tests\Impl\Mysql\Repositories\MysqlRandomizer;
use SConcur\Tests\Impl\Mysql\Repositories\TestMysqlDto;
use SConcur\Tests\Impl\Mysql\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-seeder',
);

$testRepository = TestMysqlResolver::getTestRepository();

$testRepository->refresh();

$sconcurClient = TestMysqlResolver::getSconcurTestClient();

$benchmarker->run(
    syncCallback: static function () use ($testRepository) {
        $query = makeTestMysqlDto();

        return $testRepository->insert($query)->rowsAffected;
    },
    asyncCallback: static function () use ($testRepository) {
        $query = makeTestMysqlDto();

        return $testRepository->insert($query)->rowsAffected;
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
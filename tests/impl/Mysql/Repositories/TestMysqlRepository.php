<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql\Repositories;

use SConcur\Features\Mysql\Connection\Client;
use SConcur\Features\Mysql\Results\ExecResult;

readonly class TestMysqlRepository
{
    public function __construct(
        protected Client $client,
        protected string $tableName,
    ) {
    }

    public function refresh(): void
    {
        $this->client->exec(
            sql: "DROP TABLE IF EXISTS $this->tableName",
        );

        $this->client->exec(
            sql: "CREATE TABLE IF NOT EXISTS $this->tableName (
                id INT AUTO_INCREMENT PRIMARY KEY,
                varchar_col VARCHAR(255) NULL,
                char_col CHAR(50) NULL,
                text_col TEXT NULL,
                tinytext_col TINYTEXT NULL,
                mediumtext_col MEDIUMTEXT NULL,
                longtext_col LONGTEXT NULL,
                tinyint_col TINYINT NULL,
                smallint_col SMALLINT NULL,
                mediumint_col MEDIUMINT NULL,
                int_col INT NULL,
                bigint_col BIGINT NULL,
                decimal_col DECIMAL(10,2) NULL,
                numeric_col NUMERIC(10,2) NULL,
                float_col FLOAT NULL,
                double_col DOUBLE NULL,
                bit_col BIT(8) NULL,
                date_col DATE NULL,
                datetime_col DATETIME NULL,
                timestamp_col TIMESTAMP NULL,
                time_col TIME NULL,
                year_col YEAR NULL,
                binary_col BINARY(16) NULL,
                varbinary_col VARBINARY(255) NULL,
                blob_col BLOB NULL,
                tinyblob_col TINYBLOB NULL,
                mediumblob_col MEDIUMBLOB NULL,
                longblob_col LONGBLOB NULL,
                enum_col ENUM('value1', 'value2', 'value3') NULL,
                set_col SET('option1', 'option2', 'option3') NULL,
                json_col JSON NULL,
                bool_col BOOLEAN NULL
            )",
        );
    }

    public function insert(TestMysqlDto $dto): ExecResult {
        $sql = "INSERT INTO $this->tableName (varchar_col, char_col, text_col, tinytext_col, mediumtext_col, longtext_col, " .
            "tinyint_col, smallint_col, mediumint_col, int_col, bigint_col, decimal_col, numeric_col, float_col, double_col, " .
            "bit_col, date_col, datetime_col, timestamp_col, time_col, year_col, binary_col, varbinary_col, blob_col, " .
            "tinyblob_col, mediumblob_col, longblob_col, enum_col, set_col, json_col, bool_col) VALUES " .
            "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $bindings = [
            $dto->varcharCol,
            $dto->charCol,
            $dto->textCol,
            $dto->tinytextCol,
            $dto->mediumtextCol,
            $dto->longtextCol,
            $dto->tinyintCol,
            $dto->smallintCol,
            $dto->mediumintCol,
            $dto->intCol,
            $dto->bigintCol,
            $dto->decimalCol,
            $dto->numericCol,
            $dto->floatCol,
            $dto->doubleCol,
            $dto->bitCol,
            $dto->dateCol,
            $dto->datetimeCol,
            $dto->timestampCol,
            $dto->timeCol,
            $dto->yearCol,
            $dto->binaryCol,
            $dto->varbinaryCol,
            $dto->blobCol,
            $dto->tinyblobCol,
            $dto->mediumblobCol,
            $dto->longblobCol,
            $dto->enumCol,
            $dto->setCol,
            $dto->jsonCol,
            $dto->boolCol,
        ];

        return $this->client->exec(
            sql: $sql,
            bindings: $bindings,
        );
    }
}

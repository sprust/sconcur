<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql\Repositories;

readonly class TestMysqlDto
{
    public function __construct(
        public ?string $varcharCol,
        public ?string $charCol,
        public ?string $textCol,
        public ?string $tinytextCol,
        public ?string $mediumtextCol,
        public ?string $longtextCol,
        public ?int $tinyintCol,
        public ?int $smallintCol,
        public ?int $mediumintCol,
        public ?int $intCol,
        public ?int $bigintCol,
        public ?float $decimalCol,
        public ?float $numericCol,
        public ?float $floatCol,
        public ?float $doubleCol,
        public ?int $bitCol,
        public ?string $dateCol,
        public ?string $datetimeCol,
        public ?string $timestampCol,
        public ?string $timeCol,
        public ?int $yearCol,
        public ?string $binaryCol,
        public ?string $varbinaryCol,
        public ?string $blobCol,
        public ?string $tinyblobCol,
        public ?string $mediumblobCol,
        public ?string $longblobCol,
        public ?string $enumCol,
        public ?string $setCol,
        public ?string $jsonCol,
        public ?int $boolCol,
    ) {
    }
}

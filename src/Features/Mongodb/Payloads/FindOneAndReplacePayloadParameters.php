<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class FindOneAndReplacePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>      $replacement
     * @param array<string, mixed>|null $projection
     */
    public function __construct(
        private array $filter,
        private array $replacement,
        private ?array $projection = null,
        private bool $upsert = false,
        private bool $returnDocument = true,
    ) {
    }

    public function getData(): array
    {
        $data = [
            'f'  => $this->filter,
            'r'  => $this->replacement,
            'ou' => $this->upsert,
            'rd' => $this->returnDocument,
        ];

        if ($this->projection !== null) {
            $data['op'] = $this->projection;
        }

        return $data;
    }
}

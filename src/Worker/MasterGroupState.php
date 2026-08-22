<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * One pool as the state file reports it: what it is called, how many workers it keeps
 * up and which script they run. Read by `status` and by anything watching the master
 * from outside.
 */
readonly class MasterGroupState
{
    public function __construct(
        public string $name,
        public int $workerCount,
        public string $workerScript,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'workerCount'  => $this->workerCount,
            'workerScript' => $this->workerScript,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            workerCount: (int) ($data['workerCount'] ?? 0),
            workerScript: (string) ($data['workerScript'] ?? ''),
        );
    }
}

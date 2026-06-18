<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Observable state of a running master, persisted as JSON next to the lock. Read by
 * the `status`/`stop` CLI commands and by external guards (cron/systemd) to decide
 * whether the master needs (re)starting.
 */
readonly class MasterState
{
    public const string STATUS_RUNNING = 'running';

    public function __construct(
        public int $pid,
        public float $startedAt,
        public int $workerCount,
        public string $workerScript,
        public ?string $address,
        public string $status = self::STATUS_RUNNING,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid'          => $this->pid,
            'startedAt'    => $this->startedAt,
            'workerCount'  => $this->workerCount,
            'workerScript' => $this->workerScript,
            'address'      => $this->address,
            'status'       => $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pid: (int) ($data['pid'] ?? 0),
            startedAt: (float) ($data['startedAt'] ?? 0.0),
            workerCount: (int) ($data['workerCount'] ?? 0),
            workerScript: (string) ($data['workerScript'] ?? ''),
            address: isset($data['address']) ? (string) $data['address'] : null,
            status: (string) ($data['status'] ?? self::STATUS_RUNNING),
        );
    }
}

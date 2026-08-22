<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Observable state of a running master, persisted as JSON next to the lock. Read by
 * the `status`/`stop` CLI commands and by external guards (cron/systemd) to decide
 * whether the master needs (re)starting.
 *
 * `workerCount` is the total across every pool; the per-pool breakdown is in `groups`.
 * The state is rewritten on a reload, so a guard reading it sees the pools that are
 * actually up rather than the ones the master started with.
 */
readonly class MasterState
{
    public const string STATUS_RUNNING = 'running';

    /**
     * @param list<MasterGroupState> $groups
     */
    public function __construct(
        public int $pid,
        public float $startedAt,
        public int $workerCount,
        public array $groups = [],
        public string $status = self::STATUS_RUNNING,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid'         => $this->pid,
            'startedAt'   => $this->startedAt,
            'workerCount' => $this->workerCount,
            'groups'      => array_map(
                static fn(MasterGroupState $group): array => $group->toArray(),
                $this->groups,
            ),
            'status'      => $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $groups = [];

        if (is_array($data['groups'] ?? null)) {
            foreach ($data['groups'] as $group) {
                if (is_array($group)) {
                    /** @var array<string, mixed> $group */
                    $groups[] = MasterGroupState::fromArray($group);
                }
            }
        }

        return new self(
            pid: (int) ($data['pid'] ?? 0),
            startedAt: (float) ($data['startedAt'] ?? 0.0),
            workerCount: (int) ($data['workerCount'] ?? 0),
            groups: $groups,
            status: (string) ($data['status'] ?? self::STATUS_RUNNING),
        );
    }
}

<?php

declare(strict_types=1);

namespace SConcur\Process\Dto;

/**
 * What a fork answers, once in each process: the child's pid and its pipes in the
 * parent, a pid of 0 and nothing else in the child.
 *
 * Every stream is non-blocking: the master reads its workers' pipes on a supervision
 * tick and must never wait on one.
 */
readonly class ForkResult
{
    /**
     * @param int           $pid       the child's pid in the parent, 0 in the child
     * @param resource|null $stdout    the parent's end of the child's stdout; null in the
     *                                 child, or when the output was not captured
     * @param resource|null $stderr    the same for stderr
     * @param resource|null $heartbeat the read end of the child's heartbeat pipe, when one
     *                                 was asked for; null in the child, whose write end the
     *                                 extension keeps (ProcessControl::markHeartbeat())
     */
    public function __construct(
        public int $pid,
        public mixed $stdout = null,
        public mixed $stderr = null,
        public mixed $heartbeat = null,
    ) {
    }

    public function isChild(): bool
    {
        return $this->pid === 0;
    }
}

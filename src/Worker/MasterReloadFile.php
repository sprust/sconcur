<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The reload trigger file (e.g. sconcur-server.reload). Its presence asks a running
 * master to roll its workers one by one; the master deletes it once the rolling
 * restart completes. File-based like the stop signal (state-file removal), so no
 * signal — and therefore no PID-reuse risk — is involved.
 */
class MasterReloadFile
{
    public function __construct(
        protected string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Requests a reload by creating the trigger file. Returns false when it could not
     * be written.
     */
    public function request(): bool
    {
        return file_put_contents($this->path, "reload\n") !== false;
    }

    public function requested(): bool
    {
        return is_file($this->path);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

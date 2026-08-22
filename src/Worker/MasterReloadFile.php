<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The reload trigger file (e.g. sconcur-server.reload). Its presence asks a running
 * master to re-read its config and roll its workers onto it, one at a time; the master
 * deletes it once the rolling restart completes. File-based like the stop signal
 * (state-file removal), so no signal — and therefore no PID-reuse risk — is involved.
 *
 * The file carries the config path the requesting CLI was given, because the master is
 * handed its groups as objects and has no path of its own to go back to. An empty or
 * unreadable value just means "roll the workers on the config already loaded".
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
     * Requests a reload by creating the trigger file, naming the config to re-read.
     * Returns false when it could not be written.
     */
    public function request(string $configPath = ''): bool
    {
        return file_put_contents($this->path, $configPath . "\n") !== false;
    }

    /**
     * The config path the request named, or an empty string when it named none (an
     * older trigger file, or one written by hand).
     */
    public function configPath(): string
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return '';
        }

        $configPath = trim($contents);

        return is_file($configPath) ? $configPath : '';
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

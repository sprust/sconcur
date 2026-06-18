<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Reads/writes the master state JSON file (e.g. sconcur-http-server-state.json).
 * Writes are atomic (temp file + rename) so a reader never sees a half-written file.
 */
class MasterStateFile
{
    public function __construct(
        protected string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(MasterState $state): void
    {
        $json = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $temporaryPath = $this->path . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporaryPath, $json . "\n") === false) {
            return;
        }

        rename($temporaryPath, $this->path);
    }

    public function read(): ?MasterState
    {
        if (!is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return null;
        }

        return MasterState::fromArray($data);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

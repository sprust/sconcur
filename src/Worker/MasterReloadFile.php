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
 * handed its groups as objects and has no path of its own to go back to, and the group
 * to roll when the request named one. It is JSON, but a file holding a bare path (or
 * nothing) is read too: written by hand, it means "roll everything on the config
 * already loaded".
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
     * Requests a reload by creating the trigger file, naming the config to re-read and,
     * optionally, the single group to roll. Returns false when it could not be written.
     */
    public function request(string $configPath = '', string $group = ''): bool
    {
        $request = (string) json_encode([
            'configPath' => $configPath,
            'group'      => $group,
        ]);

        return file_put_contents($this->path, $request . "\n") !== false;
    }

    /**
     * The config path the request named, or an empty string when it named none (a
     * trigger file written by hand).
     */
    public function configPath(): string
    {
        $request = $this->read();

        $configPath = (string) ($request['configPath'] ?? '');

        return is_file($configPath) ? $configPath : '';
    }

    /**
     * The single group the request asked to roll, or an empty string for all of them.
     */
    public function group(): string
    {
        return (string) ($this->read()['group'] ?? '');
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

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return [];
        }

        $contents = trim($contents);

        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        // A file written by hand: a bare config path, or a word like "reload".
        return ['configPath' => $contents];
    }
}

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
     * The pending request, or null when there is none. Read in one go: the master asks
     * what it names and which group it asks for on the same tick, and two reads could
     * answer from two different files.
     */
    public function pending(): ?MasterReloadRequest
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $contents = trim($contents);

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            // A file written by hand: a bare config path, or a word like "reload".
            $decoded = ['configPath' => $contents];
        }

        /** @var array<string, mixed> $decoded */
        return new MasterReloadRequest(
            configPath: (string) ($decoded['configPath'] ?? ''),
            group: (string) ($decoded['group'] ?? ''),
            // The signature is what tells one request from the next, so a second one
            // written while the first was still rolling is served rather than swallowed
            // by the clear that ends the first.
            signature: hash('xxh128', $contents . '|' . (string) @filemtime($this->path)),
        );
    }

    public function requested(): bool
    {
        return is_file($this->path);
    }

    /**
     * Removes the trigger, but only when it still holds the request that was served.
     *
     * A reload takes several ticks to roll, and an operator may ask for another one
     * meanwhile. Clearing unconditionally would delete that second request unread, and
     * the change it asked for would never reach the workers.
     */
    public function clear(string $servedSignature = ''): void
    {
        if (!is_file($this->path)) {
            return;
        }

        if ($servedSignature !== '') {
            $pending = $this->pending();

            if ($pending !== null && $pending->signature !== $servedSignature) {
                return;
            }
        }

        @unlink($this->path);
    }
}

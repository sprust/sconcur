<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The master's handle on a fork server: a php of its own, started like an exec'd worker, with
 * three channels instead of a worker's output pipes — commands to its stdin, events from
 * its EVENTS_FD, and its stderr, which the master logs as a worker's. Its stdout is the
 * master's own: the fork server writes the journal itself, and with `logTo` stdout that is
 * where its lines go, one write each.
 */
class ForkServerProcess extends ExecWorkerProcess
{
    /** @var resource|null */
    protected mixed $commandsPipe = null;

    /** @var resource|null */
    protected mixed $eventsPipe = null;

    protected string $eventsBuffer = '';

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param list<int>             $keepDescriptors the group's listening socket, which its workers accept on
     */
    public function __construct(array $command, string $cwd, array $env, array $keepDescriptors = [])
    {
        $pipes = $this->open(
            command: $command,
            cwd: $cwd,
            env: $env,
            descriptors: [
                0                     => ['pipe', 'r'],
                1                     => STDOUT,
                2                     => ['pipe', 'w'],
                ForkServer::EVENTS_FD => ['pipe', 'w'],
            ],
            keepDescriptors: $keepDescriptors,
        );

        $this->commandsPipe = $pipes[0] ?? null;
        $this->stderrPipe   = $pipes[2] ?? null;
        $this->eventsPipe   = $pipes[ForkServer::EVENTS_FD] ?? null;

        foreach ([$this->stderrPipe, $this->eventsPipe] as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }
    }

    /**
     * @param array<string, mixed> $command
     */
    public function send(array $command): void
    {
        if (is_resource($this->commandsPipe)) {
            @fwrite($this->commandsPipe, json_encode($command) . "\n");
        }
    }

    /**
     * The events the fork server wrote since the last call, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function takeEvents(): array
    {
        $this->eventsBuffer .= $this->readAvailable($this->eventsPipe);

        $events = [];

        while (($newline = strpos($this->eventsBuffer, "\n")) !== false) {
            $event = json_decode(substr($this->eventsBuffer, 0, $newline), true);

            $this->eventsBuffer = substr($this->eventsBuffer, $newline + 1);

            if (is_array($event) && isset($event['e'])) {
                /** @var array<string, mixed> $event */
                $events[] = $event;
            }
        }

        return $events;
    }

    public function close(): void
    {
        foreach ([$this->commandsPipe, $this->eventsPipe] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->commandsPipe = null;
        $this->eventsPipe   = null;

        parent::close();
    }
}

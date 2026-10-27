<?php

/**
 * The worker master the tests start: reads the JSON a test wrote (TestWorkerMaster), builds
 * a WorkerMaster from it and runs it.
 *
 * Usage: php -d extension=sconcur.so tests/servers/worker/master.php <config.json>
 *
 * The JSON keeps the shape the tests have always written: master settings at the top, the
 * group settings beside them as defaults, and a "groups" list. A group names a test worker
 * script, its argv flags (`server`, `workerArgs`) and the settings of a WorkerGroupConfig.
 * Its role sets up argv the way a php started on the script would see it — the flags, then
 * `--masterPid` — and requires the script; the script builds its server from argv as it
 * would on its own. A top-level "preload" lists files the master requires before it builds
 * the WorkerMaster, the way an application loads before its master: every worker is a fork
 * that begins with them loaded.
 *
 * Two group keys set a worker apart by its slot, for the load harnesses that place the
 * workers themselves: "slotCpus", a list of CPU lists, pins slot i to entry i (wrapping)
 * before it starts anything, the way `taskset -c` would pin a php of its own; and
 * "distinctPorts" gives slot i the port of the `server` address plus 1 + i, a port of
 * its own, so a proxy in front can choose the worker.
 *
 * Exit codes: what WorkerMaster::run() returns; 1 when it throws, the reason on stderr.
 */

declare(strict_types=1);

use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\LogTarget;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerContext;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * A group's `server` block as argv flags: a boolean as 1/0, a list or an object as JSON.
 *
 * @param array<string, mixed> $server
 *
 * @return list<string>
 */
function testMasterServerFlags(array $server): array
{
    $flags = [];

    foreach ($server as $key => $value) {
        $flags[] = '--' . $key . '=' . match (true) {
            is_bool($value)   => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    return $flags;
}

/**
 * @param array<string, mixed> $group    the group's own keys
 * @param array<string, mixed> $defaults the master-wide keys it falls back on
 */
function testMasterGroup(array $group, array $defaults): WorkerGroupConfig
{
    $setting = static fn(string $key, mixed $default): mixed => $group[$key] ?? $defaults[$key] ?? $default;

    $script        = (string) $group['workerScript'];
    $server        = (array) ($group['server'] ?? []);
    $workerArgs    = array_map(strval(...), (array) ($group['workerArgs'] ?? []));
    $slotCpus      = array_values(array_map(strval(...), (array) ($group['slotCpus'] ?? [])));
    $distinctPorts = (bool) ($group['distinctPorts'] ?? false);

    return new WorkerGroupConfig(
        name: (string) ($group['name'] ?? 'default'),
        role: static function (WorkerContext $context) use ($script, $server, $workerArgs, $slotCpus, $distinctPorts): void {
            // Before the server starts its runtime: the threads it starts then take the
            // affinity of this one.
            if ($slotCpus !== []) {
                exec(sprintf('taskset -cp %s %d', escapeshellarg($slotCpus[$context->slot % count($slotCpus)]), getmypid()));
            }

            if ($distinctPorts) {
                $address = (string) $server['address'];
                $port    = (int) substr($address, (int) strrpos($address, ':') + 1);

                $server['address'] = substr($address, 0, (int) strrpos($address, ':')) . ':' . ($port + 1 + $context->slot);
            }

            $argv = [$script, ...testMasterServerFlags($server), ...$workerArgs, '--masterPid=' . $context->masterPid];
            $argc = count($argv);

            $_SERVER['argv'] = $argv;
            $_SERVER['argc'] = $argc;

            $GLOBALS['argv'] = $argv;
            $GLOBALS['argc'] = $argc;

            require $script;
        },
        workerCount: (int) ($group['workerCount'] ?? 0),
        restartPolicy: RestartPolicy::from((string) $setting('restartPolicy', 'always')),
        shutdownTimeoutMs: (int) $setting('shutdownTimeoutMs', 10_000),
        restartBackoffMs: (int) $setting('restartBackoffMs', 200),
        maxRestartBackoffMs: (int) $setting('maxRestartBackoffMs', 30_000),
        watchdogTimeoutMs: (int) $setting('watchdogTimeoutMs', 60_000),
        readyTimeoutMs: (int) $setting('readyTimeoutMs', 30_000),
        accessLog: AccessLogTarget::from((string) $setting('accessLog', 'stdout')),
        env: array_map(strval(...), [...(array) ($defaults['env'] ?? []), ...(array) ($group['env'] ?? [])]),
    );
}

try {
    $config = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), true, flags: JSON_THROW_ON_ERROR);

    foreach ((array) ($config['preload'] ?? []) as $file) {
        require_once (string) $file;
    }

    $groups = [];

    foreach ((array) $config['groups'] as $group) {
        $groups[] = testMasterGroup(
            group: (array) $group,
            defaults: $config,
        );
    }

    $master = new WorkerMaster(
        runtimeDir: (string) $config['runtimeDir'],
        groups: $groups,
        logDir: isset($config['logDir']) ? (string) $config['logDir'] : null,
        name: (string) ($config['name'] ?? WorkerMaster::DEFAULT_NAME),
        rotateDays: (int) ($config['rotateDays'] ?? 3),
        logTo: LogTarget::from((string) ($config['logTo'] ?? 'file')),
        panelPort: (int) ($config['panelPort'] ?? 0),
        adminToken: (string) ($config['adminToken'] ?? ''),
    );

    exit($master->run());
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(1);
}

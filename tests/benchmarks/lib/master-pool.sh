# Starts a pool of demo-server workers the way a pool runs in production: a worker master
# (tests/servers/worker/master.php) in the php container loads what the servers build on
# (tests/servers/preload.php) and forks the workers, each running the demo server script
# as its role. Sourced by the load harnesses, which run on the host.
#
# Needs DOCKER_COMPOSE and EXTENSION set by the caller.
#
#   master_pool_start NAME SCRIPT WORKERS ADDRESS PIDFILE STDERRLOG
#
# NAME names the master's config, runtime directory and group; the workers show in the
# process list as `sconcur-server: NAME #<slot>`, which is how they are found
# (MASTER_POOL_WORKER_PIDS). The master's pid goes to PIDFILE: SIGTERM to it drains the
# whole pool, and the workers leave with it. What the master and the workers write to
# stderr before the journal is open goes to STDERRLOG; the rest is in the journal
# (master_pool_journal_tail).
#
# Optional, from the environment:
#   POOL_MAX_CONCURRENCY  the server's --maxConcurrency (0)
#   POOL_ARGS             more --flags for the server, space-separated
#   POOL_ENV              VAR=value assignments for the workers' environment, space-separated
#   POOL_SLOT_CPUS        CPU lists, space-separated: slot i is pinned to entry i (wrapping)
#   POOL_MASTER_CPUS      a CPU list for the master; its forks inherit it
#   POOL_DISTINCT_PORTS   1: slot i takes the port of ADDRESS plus 1 + i, without reusePort
#   POOL_ACCESS_LOG       the group's accessLog: off (default) or file, which writes every
#                         worker's lines to <runtime>/NAME-access-<date>.log with its slot

master_pool_dir() {
    echo "/tmp/sc-pool-$1"
}

master_pool_start() {
    local name=$1 script=$2 workers=$3 address=$4 pidfile=$5 errlog=$6
    local dir
    dir=$(master_pool_dir "$name")

    python3 - "$dir" "$name" "$script" "$workers" "$address" <<'PY' |
import json, os, shlex, sys
runtime, name, script, workers, address = sys.argv[1:]
env = {}
for assignment in shlex.split(os.environ.get('POOL_ENV', '')):
    key, _, value = assignment.partition('=')
    env[key] = value
print(json.dumps({
    'runtimeDir': runtime,
    'logDir': runtime,
    'name': name,
    'logTo': 'file',
    'preload': ['/sconcur/tests/servers/preload.php'],
    'groups': [{
        'name': name,
        'workerScript': script,
        'workerCount': int(workers),
        'workerArgs': shlex.split(os.environ.get('POOL_ARGS', '')),
        'server': {
            'address': address,
            'reusePort': True,
            'maxConcurrency': int(os.environ.get('POOL_MAX_CONCURRENCY', '0') or 0),
        },
        'slotCpus': os.environ.get('POOL_SLOT_CPUS', '').split(),
        'distinctPorts': os.environ.get('POOL_DISTINCT_PORTS', '0') == '1',
        'accessLog': os.environ.get('POOL_ACCESS_LOG', '') or 'off',
        'env': env,
    }],
}, indent=2))
PY
        $DOCKER_COMPOSE exec -T php sh -c 'rm -rf "'"$dir"'" && mkdir -p "'"$dir"'" && cat > "'"$dir"'/config.json"'

    local pin=""
    [ -n "${POOL_MASTER_CPUS:-}" ] && pin="taskset -c $POOL_MASTER_CPUS"

    $DOCKER_COMPOSE exec -T php sh -c '
        : > "'"$errlog"'"
        '"$pin"' php -d extension='"$EXTENSION"' /sconcur/tests/servers/worker/master.php "'"$dir"'/config.json" \
            >/dev/null 2>>"'"$errlog"'" &
        echo $! > "'"$pidfile"'"
    '
}

# A shell snippet, for `sh -c` inside the container, that prints the pids of the pool's
# workers, one a line. No procps there, so /proc is scanned.
master_pool_worker_pids() {
    echo '
        for d in /proc/[0-9]*; do
            case "$(tr "\0" " " < "$d/cmdline" 2>/dev/null)" in
                "sconcur-server: '"$1"' #"*) echo "${d#/proc/}" ;;
            esac
        done
    '
}

# The last lines of the master's journal: why a pool did not come up.
master_pool_journal_tail() {
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$(master_pool_dir "$1")"'"/'"$1"'-2*.log 2>/dev/null' >&2 || true
}

# Kills, with SIGKILL, a master or worker of this pool left by an earlier run whose
# pidfile is gone: a worker still on the port would take a share of the next run.
master_pool_kill_stray() {
    $DOCKER_COMPOSE exec -T php sh -c '
        self=$$
        for d in /proc/[0-9]*; do
            pid=${d#/proc/}
            [ "$pid" = "$self" ] && continue
            case "$(tr "\0" " " < "$d/cmdline" 2>/dev/null)" in
                "sconcur-server: '"$1"' #"*|*"'"$(master_pool_dir "$1")"'/config.json"*)
                    echo "WARNING: stray process $pid of pool '"$1"' from an earlier run; killed" >&2
                    kill -9 "$pid" 2>/dev/null || true
                    ;;
            esac
        done
    ' || true
}

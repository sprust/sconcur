#!/usr/bin/env bash
#
# SO_REUSEPORT throughput benchmark: spawn one demo HTTP-server process per core
# (all binding the same port with SO_REUSEPORT, the kernel load-balancing across
# them) inside the `php` container, then drive them with wrk from the host.
#
# Why this setup gives an honest number:
#   * Servers and the load generator are pinned to DISJOINT core sets (taskset),
#     so the client never steals CPU from the server — the classic localhost trap.
#   * wrk targets the container's bridge IP DIRECTLY, bypassing the docker-proxy
#     userland NAT that a published port (e.g. localhost:28080) goes through and
#     that silently caps throughput.
#
# It measures the server's per-core ceiling scaled across cores — not a through-NAT
# production path. Run it from the HOST (where wrk lives); the servers run in the
# container (where the extension is built).
#
# Usage:
#   tests/benchmarks/http-throughput.sh
#   ROUTE=/ DURATION=15 CONNECTIONS=256 SERVERS=16 WRK_THREADS=4 \
#       tests/benchmarks/http-throughput.sh
#
# Tunables (env vars):
#   SERVERS       server processes / cores to use            (default: cores - WRK_THREADS)
#   WRK_THREADS   wrk threads / cores reserved for the client (default: 4)
#   CONNECTIONS   total keep-alive connections wrk opens       (default: 256)
#   DURATION      load duration, seconds                       (default: 15)
#   PORT          in-container listen port                     (default: 18080)
#   ROUTE         path to hit                                  (default: /)
#   MAXCONCURRENCY per-process maxConcurrency (0 = unlimited)  (default: 0)
#   PIN_SERVERS   1 = pin each server to its own core (process-per-core, like
#                 nginx workers); 0 = leave them unpinned so each process's Go
#                 runtime may spread across cores                (default: 1)
#
set -euo pipefail

cd "$(dirname "$0")/../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18080}
ROUTE=${ROUTE:-/}
DURATION=${DURATION:-15}
CONNECTIONS=${CONNECTIONS:-256}
WRK_THREADS=${WRK_THREADS:-4}
MAXCONCURRENCY=${MAXCONCURRENCY:-0}
PIN_SERVERS=${PIN_SERVERS:-1}

EXTENSION=/sconcur/ext/build/sconcur.so
SCRIPT=/sconcur/tests/servers/http/http-server.php
# The container has no procps (pkill/pgrep), so the spawn loop records each PID to
# this file and teardown/liveness use plain kill / kill -0 (shell builtins).
PIDFILE=/tmp/sc-http-throughput-$PORT.pids
# Server stderr is collected here so a failed startup can be diagnosed.
STDERRLOG=/tmp/sc-http-throughput-$PORT.err

command -v wrk >/dev/null || { echo "wrk not found on host (install it: apt-get install wrk)"; exit 1; }

CORES=$(nproc)
: "${SERVERS:=$(( CORES - WRK_THREADS ))}"
(( SERVERS >= 1 )) || SERVERS=1

# Server cores: 0..SERVERS-1. wrk cores: the remainder (or the top WRK_THREADS if
# the servers claimed everything — then client/server overlap, reported below).
if (( SERVERS < CORES )); then
    WRK_CPULIST="${SERVERS}-$(( CORES - 1 ))"
else
    WRK_CPULIST="$(( CORES - WRK_THREADS ))-$(( CORES - 1 ))"
fi

CID=$($DOCKER_COMPOSE ps -q php)
[ -n "$CID" ] || { echo "php container is not running (make up)"; exit 1; }
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$CID")
[ -n "$IP" ] || { echo "could not resolve php container IP"; exit 1; }

stop_servers() {
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        while read -r pid; do kill "$pid" 2>/dev/null || true; done < "'"$PIDFILE"'"
        rm -f "'"$PIDFILE"'"
    ' 2>/dev/null || true
}

# Kills any demo server still bound to this port, including ones leaked by an
# earlier interrupted run (whose PID never made it into the pidfile). No procps in
# the container, so scan /proc; the glob is expanded once, so transient greps are
# not in the list, and the scanning shell itself ($$) is skipped so it cannot kill
# itself (its own cmdline contains the match string).
kill_stray() {
    $DOCKER_COMPOSE exec -T php sh -c '
        self=$$
        for d in /proc/[0-9]*; do
            pid=${d#/proc/}
            [ "$pid" = "$self" ] && continue
            [ -r "$d/cmdline" ] || continue
            if grep -qa "address=0.0.0.0:'"$PORT"'" "$d/cmdline" 2>/dev/null; then
                kill "$pid" 2>/dev/null || true
            fi
        done
    ' 2>/dev/null || true
}

trap stop_servers EXIT

echo "=================================================================="
echo " SO_REUSEPORT throughput benchmark"
echo "   host cores      : $CORES"
if [ "$PIN_SERVERS" = "1" ]; then
    echo "   server procs    : $SERVERS  (pinned to cores 0-$(( SERVERS - 1 )), reusePort)"
else
    echo "   server procs    : $SERVERS  (unpinned, reusePort)"
fi
echo "   wrk threads     : $WRK_THREADS (pinned to cores $WRK_CPULIST)"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s"
echo "   maxConcurrency  : $MAXCONCURRENCY (per process)"
echo "   target          : http://$IP:$PORT$ROUTE  (container bridge IP, no NAT)"
echo "=================================================================="

# Clear any leftovers on this port — both cleanly-tracked (pidfile) and leaked from
# an interrupted run — so new servers do not contend with zombies, then spawn one
# per core, recording each PID. The spawn uses a SYNCHRONOUS exec (not -d): the
# loop backgrounds each server and the exec returns once the loop finished, so the
# pidfile is fully written before we proceed (detached -d ran unreliably). The
# backgrounded servers outlive the exec's shell (reparented to the container init).
# taskset execs into php (same PID), so the recorded PID is the server itself;
# server stderr goes to $STDERRLOG for diagnosis.
stop_servers
kill_stray

$DOCKER_COMPOSE exec -T php sh -c '
    : > "'"$PIDFILE"'"
    : > "'"$STDERRLOG"'"
    i=0
    while [ "$i" -lt "'"$SERVERS"'" ]; do
        if [ "'"$PIN_SERVERS"'" = "1" ]; then pin="taskset -c $i"; else pin=""; fi
        $pin php -d extension='"$EXTENSION"' '"$SCRIPT"' \
            --address=0.0.0.0:'"$PORT"' --reusePort=1 --maxConcurrency='"$MAXCONCURRENCY"' \
            >/dev/null 2>>"'"$STDERRLOG"'" &
        echo $! >> "'"$PIDFILE"'"
        i=$(( i + 1 ))
    done
'

# Wait until the port answers from the host (via the container IP); 15s budget.
ready=0
for _ in $(seq 1 150); do
    if curl -fsS -o /dev/null --max-time 1 "http://$IP:$PORT$ROUTE" 2>/dev/null; then
        ready=1
        break
    fi
    sleep 0.1
done

if (( ready != 1 )); then
    echo "servers did not become reachable on $IP:$PORT" >&2
    alive=$($DOCKER_COMPOSE exec -T php sh -c '
        n=0
        while read -r pid; do kill -0 "$pid" 2>/dev/null && n=$(( n + 1 )); done < "'"$PIDFILE"'" 2>/dev/null
        echo "$n"
    ' | tr -d '[:space:]')
    echo "  alive server procs: ${alive:-0}/$SERVERS" >&2
    echo "  --- server stderr (last 20 lines) ---" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    exit 1
fi

alive=$($DOCKER_COMPOSE exec -T php sh -c '
    n=0
    while read -r pid; do kill -0 "$pid" 2>/dev/null && n=$(( n + 1 )); done < "'"$PIDFILE"'"
    echo "$n"
' | tr -d '[:space:]')
echo "servers up: $alive/$SERVERS"
echo

taskset -c "$WRK_CPULIST" wrk \
    -t"$WRK_THREADS" \
    -c"$CONNECTIONS" \
    -d"${DURATION}s" \
    --latency \
    "http://$IP:$PORT$ROUTE"

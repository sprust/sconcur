#!/usr/bin/env bash
#
# SO_REUSEPORT throughput benchmark: start one demo HTTP-server worker per core
# (all binding the same port with SO_REUSEPORT, the kernel load-balancing across
# them) under a worker master inside the `php` container — the master loads what the
# server builds on and forks the workers (tests/benchmarks/lib/master-pool.sh) — then
# drive them with wrk from the host.
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
#   tests/benchmarks/http/throughput.sh
#   ROUTE=/ DURATION=15 CONNECTIONS=256 SERVERS=16 WRK_THREADS=4 \
#       tests/benchmarks/http/throughput.sh
#
# Tunables (env vars):
#   SERVERS       workers / cores to use                      (default: cores - WRK_THREADS)
#   WRK_THREADS   wrk threads / cores reserved for the client (default: 4)
#   CONNECTIONS   total keep-alive connections wrk opens       (default: 256)
#   STOP_TIMEOUT  seconds the pool gets to exit after SIGTERM  (default: 30)
#   DURATION      load duration, seconds                       (default: 15)
#   PORT          in-container listen port                     (default: 18080)
#   ROUTE         path to hit                                  (default: /)
#   MAXCONCURRENCY per-worker maxConcurrency (0 = unlimited)   (default: 0)
#   PIN_SERVERS   1 = pin each worker to its own core (process-per-core, like
#                 nginx workers; the worker pins itself before its runtime
#                 starts); 0 = leave them unpinned so each worker's runtime
#                 may spread across cores                        (default: 1)
#
set -euo pipefail

cd "$(dirname "$0")/../../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18080}
ROUTE=${ROUTE:-/}
DURATION=${DURATION:-15}
CONNECTIONS=${CONNECTIONS:-256}
WRK_THREADS=${WRK_THREADS:-4}
MAXCONCURRENCY=${MAXCONCURRENCY:-0}
PIN_SERVERS=${PIN_SERVERS:-1}

EXTENSION=${SCONCUR_EXT:-/sconcur/ext/build/sconcur.so}
SCRIPT=/sconcur/tests/servers/http/http-server.php
# The master's PID: SIGTERM to it drains the whole pool. The container has no procps
# (pkill/pgrep), so teardown uses plain kill (a shell builtin).
PIDFILE=/tmp/sc-http-throughput-$PORT.pids
# What the master writes to stderr before its journal is open.
STDERRLOG=/tmp/sc-http-throughput-$PORT.err
# The pool's name: its master's directory, and the title its workers show.
POOL=throughput-$PORT

# shellcheck source=../lib/master-pool.sh
. tests/benchmarks/lib/master-pool.sh

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

# SIGTERM to the master first, the way production stops a pool, and then wait for
# it to go: it drains its workers and leaves after them. One still there after
# STOP_TIMEOUT seconds is killed and named on stderr; its workers see their master
# gone and leave on their own, and a worker that did not would keep its
# SO_REUSEPORT socket and take a share of the next run's traffic — which is what
# kill_stray at the next start is for. A zombie counts as gone.
STOP_TIMEOUT=${STOP_TIMEOUT:-30}

stop_servers() {
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        pids=$(cat "'"$PIDFILE"'")
        for pid in $pids; do kill "$pid" 2>/dev/null || true; done
        waited=0
        while :; do
            alive=""
            for pid in $pids; do
                if [ -d "/proc/$pid" ] && ! grep -q "^State:.*Z" "/proc/$pid/status" 2>/dev/null; then
                    alive="$alive $pid"
                fi
            done
            [ -z "$alive" ] && break
            if [ "$waited" -ge '"$STOP_TIMEOUT"' ]; then
                echo "WARNING: master$alive still running '"$STOP_TIMEOUT"'s after SIGTERM; killed" >&2
                for pid in $alive; do kill -9 "$pid" 2>/dev/null || true; done
                break
            fi
            sleep 1
            waited=$(( waited + 1 ))
        done
        rm -f "'"$PIDFILE"'"
    ' || true
}

# Kills a master or worker of this pool leaked by an earlier interrupted run (whose
# PID never made it into the pidfile).
kill_stray() {
    master_pool_kill_stray "$POOL"
}

trap stop_servers EXIT

echo "=================================================================="
echo " SO_REUSEPORT throughput benchmark"
echo "   host cores      : $CORES"
if [ "$PIN_SERVERS" = "1" ]; then
    echo "   workers         : $SERVERS  (pinned to cores 0-$(( SERVERS - 1 )), SO_REUSEPORT, forks of a master)"
else
    echo "   workers         : $SERVERS  (unpinned, SO_REUSEPORT, forks of a master)"
fi
echo "   wrk threads     : $WRK_THREADS (pinned to cores $WRK_CPULIST)"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s"
echo "   maxConcurrency  : $MAXCONCURRENCY (per worker)"
echo "   target          : http://$IP:$PORT$ROUTE  (container bridge IP, no NAT)"
echo "=================================================================="

# Clear any leftovers of this pool — both cleanly-tracked (pidfile) and leaked from
# an interrupted run — so new workers do not contend with old ones, then start the
# master, which forks one worker per core.
stop_servers
kill_stray

POOL_SLOT_CPUS=""
[ "$PIN_SERVERS" = "1" ] && POOL_SLOT_CPUS=$(seq -s ' ' 0 $(( SERVERS - 1 )))

POOL_MAX_CONCURRENCY=$MAXCONCURRENCY POOL_SLOT_CPUS=$POOL_SLOT_CPUS \
    master_pool_start "$POOL" "$SCRIPT" "$SERVERS" "0.0.0.0:$PORT" "$PIDFILE" "$STDERRLOG"

WORKER_PIDS=$(master_pool_worker_pids "$POOL")

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
    alive=$($DOCKER_COMPOSE exec -T php sh -c '('"$WORKER_PIDS"') | wc -l' | tr -d '[:space:]')
    echo "  alive workers: ${alive:-0}/$SERVERS" >&2
    echo "  --- master stderr and journal (last 20 lines) ---" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    master_pool_journal_tail "$POOL"
    exit 1
fi

alive=$($DOCKER_COMPOSE exec -T php sh -c '('"$WORKER_PIDS"') | wc -l' | tr -d '[:space:]')
echo "workers up: $alive/$SERVERS"
echo

taskset -c "$WRK_CPULIST" wrk \
    -t"$WRK_THREADS" \
    -c"$CONNECTIONS" \
    -d"${DURATION}s" \
    --latency \
    "http://$IP:$PORT$ROUTE"

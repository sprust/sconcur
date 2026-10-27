#!/usr/bin/env bash
#
# All-features WebSocket load + resource benchmark. Starts a pool of N demo ws-server
# workers (SO_REUSEPORT, one per core) under a worker master in the `php` container —
# the master loads what the server builds on and forks the workers, each pinning itself
# to its core (tests/benchmarks/lib/master-pool.sh) — drives them with the ws-load
# generator (the WS counterpart of wrk) on the "all" command — which fans out across
# EVERY async I/O feature (Sleeper, MongoDB, MySQL, PostgreSQL) concurrently per
# message — and samples CPU/memory of the server and backend containers throughout,
# plus per-worker RSS (leak check).
#
# Unlike the HTTP load test, both the servers and the load generator run inside the
# `php` container (WebSocket needs no host tooling); they are pinned to disjoint cores
# (taskset) and the generator hits the pool over loopback (127.0.0.1, no NAT).
#
# Run from the HOST (docker lives there); servers and the generator run in the container.
#
# Usage:
#   tests/benchmarks/ws/load-stats.sh
#   SERVERS=8 CONNECTIONS=256 DURATION=20 tests/benchmarks/ws/load-stats.sh
#
# Tunables (env): SERVERS, LOAD_CORES, CONNECTIONS, DURATION, PORT, MSG (=all),
#   MAXCONCURRENCY, SAMPLE_INTERVAL (resource-sampling period, s), MODE (=soak),
#   STOP_TIMEOUT (seconds the pool gets to exit after SIGTERM, default 30).
set -euo pipefail

export LC_ALL=C

cd "$(dirname "$0")/../../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18090}
MSG=${MSG:-all}
MODE=${MODE:-}

if [ "$MODE" = "soak" ]; then
    DURATION=${DURATION:-600}
    CONNECTIONS=${CONNECTIONS:-128}
    SAMPLE_INTERVAL=${SAMPLE_INTERVAL:-15}
else
    DURATION=${DURATION:-20}
    CONNECTIONS=${CONNECTIONS:-256}
    SAMPLE_INTERVAL=${SAMPLE_INTERVAL:-2}
fi

# Cores reserved for the load generator (the rest run the server pool).
LOAD_CORES=${LOAD_CORES:-2}
MAXCONCURRENCY=${MAXCONCURRENCY:-0}

EXTENSION=${SCONCUR_EXT:-/sconcur/ext/build/sconcur.so}
SCRIPT=/sconcur/tests/servers/ws/ws-server.php
LOADBIN=/tmp/sc-ws-load
# The master's pid: SIGTERM to it drains the whole pool.
PIDFILE=/tmp/sc-ws-load-$PORT.pids
STDERRLOG=/tmp/sc-ws-load-$PORT.err
# The pool's name: its master's directory, and the title its workers show.
POOL=ws-load-$PORT

# shellcheck source=../lib/master-pool.sh
. tests/benchmarks/lib/master-pool.sh

PHP_CID=$($DOCKER_COMPOSE ps -q php)
[ -n "$PHP_CID" ] || { echo "php container is not running (make up)"; exit 1; }

CORES=$($DOCKER_COMPOSE exec -T php nproc | tr -d '[:space:]')
: "${SERVERS:=$(( CORES - LOAD_CORES ))}"
(( SERVERS >= 1 )) || SERVERS=1

# Servers pinned to cores 0..SERVERS-1; the load generator to the remaining cores.
if (( SERVERS < CORES )); then
    LOAD_CPULIST="${SERVERS}-$(( CORES - 1 ))"
else
    LOAD_CPULIST="0-$(( CORES - 1 ))"
fi

MONGO_CID=$($DOCKER_COMPOSE ps -q mongodb 2>/dev/null || true)
MYSQL_CID=$($DOCKER_COMPOSE ps -q mysql 2>/dev/null || true)
PG_CID=$($DOCKER_COMPOSE ps -q postgres 2>/dev/null || true)

WATCH=$(printf 'php\t%s\nmongodb\t%s\nmysql\t%s\npostgres\t%s\n' "$PHP_CID" "$MONGO_CID" "$MYSQL_CID" "$PG_CID" | awk -F'\t' '$2!=""')
WATCH_CIDS=$(printf '%s\n' "$WATCH" | awk -F'\t' '{print $2}')

# SIGTERM to the master, which drains its workers and leaves after them; waited for, so
# the next run does not find this pool still on the port. One still there after
# STOP_TIMEOUT seconds is killed, and its workers leave once they see it gone.
STOP_TIMEOUT=${STOP_TIMEOUT:-30}

stop_servers() {
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        pid=$(cat "'"$PIDFILE"'")
        kill "$pid" 2>/dev/null || true
        waited=0
        while [ -d "/proc/$pid" ] && ! grep -q "^State:.*Z" "/proc/$pid/status" 2>/dev/null; do
            if [ "$waited" -ge '"$STOP_TIMEOUT"' ]; then
                echo "WARNING: master $pid still running '"$STOP_TIMEOUT"'s after SIGTERM; killed" >&2
                kill -9 "$pid" 2>/dev/null || true
                break
            fi
            sleep 1
            waited=$(( waited + 1 ))
        done
        rm -f "'"$PIDFILE"'"
    ' || true
}
trap stop_servers EXIT

echo "=================================================================="
echo " All-features WebSocket load + resource benchmark${MODE:+  [$MODE]}"
echo "   container cores : $CORES"
echo "   workers         : $SERVERS  (pinned to cores 0-$(( SERVERS - 1 )), SO_REUSEPORT, forks of a master)"
echo "   load generator  : pinned to cores $LOAD_CPULIST"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s   (sampling every ${SAMPLE_INTERVAL}s)"
echo "   message         : \"$MSG\"$([ "$MSG" = all ] && echo '  (fans out across all I/O features)')"
echo "   target          : ws://127.0.0.1:$PORT/  (loopback in the php container)"
echo "=================================================================="

stop_servers
master_pool_kill_stray "$POOL"

# The load generator, built once before the run: wrk speaks HTTP only, so the WS
# side needs one of its own (ws-load/, a crate separate from the core so that
# building the extension never waits on a benchmark tool).
$DOCKER_COMPOSE exec -T php sh -c '
    cd /sconcur/tests/benchmarks/ws/ws-load \
        && cargo build --release --quiet \
        && cp target/release/ws-load "'"$LOADBIN"'"
' || { echo "could not build the WS load generator" >&2; exit 2; }

POOL_MAX_CONCURRENCY=$MAXCONCURRENCY POOL_SLOT_CPUS=$(seq -s ' ' 0 $(( SERVERS - 1 ))) \
    master_pool_start "$POOL" "$SCRIPT" "$SERVERS" "0.0.0.0:$PORT" "$PIDFILE" "$STDERRLOG"

# The pids whose memory is summed: the workers, by their title.
WORKER_PIDS=$(master_pool_worker_pids "$POOL")

# Wait until the pool accepts TCP, then a short warm-up run triggers the lazy per-worker
# DB connects (so the measured run is not skewed by cold start).
ready=0
for _ in $(seq 1 150); do
    if $DOCKER_COMPOSE exec -T php php -r 'exit(@fsockopen("127.0.0.1", '"$PORT"', $e, $s, 1) ? 0 : 1);' 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done
if (( ready != 1 )); then
    echo "ws server pool did not become reachable on 127.0.0.1:$PORT" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    master_pool_journal_tail "$POOL"
    exit 1
fi

$DOCKER_COMPOSE exec -T php sh -c \
    "$LOADBIN -url ws://127.0.0.1:$PORT/ -conns 8 -duration 2 -msg '$MSG'" >/dev/null 2>&1 || true

echo "servers up; warm-up done. starting load + sampling..."
echo

SAMPLES=$(mktemp)
RSS=$(mktemp)
MARKER=$(mktemp)
trap 'rm -f "$SAMPLES" "$RSS" "$MARKER"; stop_servers' EXIT

SAMPLE_START=$(date +%s)
(
    while [ -f "$MARKER" ]; do
        docker stats --no-stream --format '{{.ID}} {{.CPUPerc}} {{.MemUsage}}' $WATCH_CIDS 2>/dev/null >> "$SAMPLES" || true
        elapsed=$(( $(date +%s) - SAMPLE_START ))
        total_kb=$($DOCKER_COMPOSE exec -T php sh -c '
            total=0
            for pid in $('"$WORKER_PIDS"'); do
                kb=$(awk "/^VmRSS:/{print \$2}" "/proc/$pid/status" 2>/dev/null)
                [ -n "$kb" ] && total=$(( total + kb ))
            done
            echo "$total"
        ' 2>/dev/null | tr -d "[:space:]") || true
        [ -n "$total_kb" ] && echo "$elapsed $total_kb" >> "$RSS"
        sleep "$SAMPLE_INTERVAL"
    done
) &
SAMPLER=$!

$DOCKER_COMPOSE exec -T php sh -c \
    "taskset -c '$LOAD_CPULIST' '$LOADBIN' -url ws://127.0.0.1:$PORT/ -conns '$CONNECTIONS' -duration '$DURATION' -msg '$MSG'"

rm -f "$MARKER"
wait "$SAMPLER" 2>/dev/null || true

echo
echo "------------------------------------------------------------------"
echo " Resource usage during load (per container: avg / peak)"
echo "------------------------------------------------------------------"
printf '%-10s %14s %14s %16s\n' "container" "CPU% avg" "CPU% peak" "MEM peak"
printf '%s\n' "$WATCH" | while IFS=$'\t' read -r name cid; do
    short=${cid:0:12}
    awk -v cid="$short" -v name="$name" '
        $1 == cid && $2 ~ /^[0-9.]+%$/ {
            cpu = $2 + 0;
            n++; sum += cpu; if (cpu > peak) peak = cpu;
            memused = $3;
        }
        END {
            if (n == 0) { printf "%-10s %14s %14s %16s\n", name, "-", "-", "-"; exit }
            printf "%-10s %14.1f %14.1f %16s\n", name, sum/n, peak, memused;
        }
    ' "$SAMPLES"
done

echo
echo "------------------------------------------------------------------"
echo " Worker RSS (sum across $SERVERS processes) — leak check"
echo "------------------------------------------------------------------"
[ "$MODE" = "soak" ] && TREND=1 || TREND=0
awk -v trend="$TREND" '
    {
        n++; te[n] = $1 + 0; mib[n] = ($2 + 0) / 1024;
        if (mib[n] > peak) peak = mib[n];
        st += te[n]; sy += mib[n]; sty += te[n] * mib[n]; stt += te[n] * te[n];
    }
    END {
        if (n == 0) { print "  (no samples)"; exit }
        if (trend == "1") {
            print "  trend (elapsed -> RSS):";
            for (i = 1; i <= n; i++) printf "    %6ds  %8.1f MiB\n", te[i], mib[i];
            print "";
        }
        printf "  first: %8.1f MiB\n", mib[1];
        printf "  peak : %8.1f MiB\n", peak;
        printf "  last : %8.1f MiB\n", mib[n];
        printf "  drift: %+.1f MiB (last - first)\n", mib[n] - mib[1];
        denom = n * stt - st * st;
        if (n >= 2 && denom != 0) {
            slope = (n * sty - st * sy) / denom * 60;
            # The first sample is taken before the load starts, so a short run is
            # mostly warm-up and its slope says nothing about a leak: the verdict is
            # left to the soak, where the warm-up is a small part of the line.
            if (trend != "1") {
                verdict = "no verdict: a short run is mostly warm-up, use MODE=soak";
            } else {
                verdict = (slope > 0.5) ? "growing — possible leak" \
                        : (slope < -0.5) ? "falling (GC / memory returned)" \
                        : "stable";
            }
            printf "  slope: %+.2f MiB/min  ->  %s\n", slope, verdict;
        }
    }
' "$RSS"

#!/usr/bin/env bash
#
# All-features WebSocket load + resource benchmark. Spawns N demo ws-server processes
# (SO_REUSEPORT, one per core) in the `php` container, drives them with the Go ws-load
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
#   tests/benchmarks/ws-load-stats.sh
#   SERVERS=8 CONNECTIONS=256 DURATION=20 tests/benchmarks/ws-load-stats.sh
#
# Tunables (env): SERVERS, LOAD_CORES, CONNECTIONS, DURATION, PORT, MSG (=all),
#   MAXCONCURRENCY, SAMPLE_INTERVAL (resource-sampling period, s), MODE (=soak).
set -euo pipefail

export LC_ALL=C

cd "$(dirname "$0")/../.."

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

EXTENSION=/sconcur/ext/build/sconcur.so
SCRIPT=/sconcur/tests/servers/ws/ws-server.php
LOADBIN=/tmp/sc-ws-load
PIDFILE=/tmp/sc-ws-load-$PORT.pids
STDERRLOG=/tmp/sc-ws-load-$PORT.err

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

stop_servers() {
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        while read -r pid; do kill "$pid" 2>/dev/null || true; done < "'"$PIDFILE"'"
        rm -f "'"$PIDFILE"'"
    ' 2>/dev/null || true
}
trap stop_servers EXIT

echo "=================================================================="
echo " All-features WebSocket load + resource benchmark${MODE:+  [$MODE]}"
echo "   container cores : $CORES"
echo "   server procs    : $SERVERS  (pinned to cores 0-$(( SERVERS - 1 )), reusePort)"
echo "   load generator  : pinned to cores $LOAD_CPULIST"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s   (sampling every ${SAMPLE_INTERVAL}s)"
echo "   message         : \"$MSG\"$([ "$MSG" = all ] && echo '  (fans out across all I/O features)')"
echo "   target          : ws://127.0.0.1:$PORT/  (loopback in the php container)"
echo "=================================================================="

stop_servers

# Build the load generator once (unpinned), then spawn one server per core.
$DOCKER_COMPOSE exec -T php sh -c 'cd /sconcur/ext && go build -o "'"$LOADBIN"'" ./cmd/ws-load'

$DOCKER_COMPOSE exec -T php sh -c '
    : > "'"$PIDFILE"'"
    : > "'"$STDERRLOG"'"
    i=0
    while [ "$i" -lt "'"$SERVERS"'" ]; do
        taskset -c "$i" php -d extension='"$EXTENSION"' '"$SCRIPT"' \
            --address=0.0.0.0:'"$PORT"' --reusePort=1 --maxConcurrency='"$MAXCONCURRENCY"' \
            >/dev/null 2>>"'"$STDERRLOG"'" &
        echo $! >> "'"$PIDFILE"'"
        i=$(( i + 1 ))
    done
'

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
            while read -r pid; do
                kb=$(awk "/^VmRSS:/{print \$2}" "/proc/$pid/status" 2>/dev/null)
                [ -n "$kb" ] && total=$(( total + kb ))
            done < "'"$PIDFILE"'"
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
            verdict = (slope > 0.5) ? "рост — возможна утечка" \
                    : (slope < -0.5) ? "снижается (GC/возврат памяти)" \
                    : "стабильно";
            printf "  slope: %+.2f MiB/min  ->  %s\n", slope, verdict;
        }
    }
' "$RSS"

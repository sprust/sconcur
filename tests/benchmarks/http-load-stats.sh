#!/usr/bin/env bash
#
# All-features load + resource benchmark. Spawns N demo HTTP-server processes
# (SO_REUSEPORT, one per core) in the `php` container, drives them with wrk on the
# /all route (which fans out across EVERY async I/O feature — Sleeper, MongoDB,
# MySQL, PostgreSQL, HTTP-client — concurrently per request), and samples CPU/memory
# of the server and backend containers throughout, plus per-worker RSS (leak check).
#
# Same honesty rules as http-throughput.sh: servers and the load generator are pinned
# to disjoint cores (taskset), and wrk hits the container bridge IP directly (no NAT).
#
# Run from the HOST (wrk + docker live there); servers run in the container.
#
# Usage:
#   tests/benchmarks/http-load-stats.sh
#   SERVERS=8 CONNECTIONS=256 DURATION=20 tests/benchmarks/http-load-stats.sh
#
# Tunables (env): SERVERS, WRK_THREADS, CONNECTIONS, DURATION, PORT, ROUTE (=/all),
#   MAXCONCURRENCY, SAMPLE_INTERVAL (resource-sampling period, s).
set -euo pipefail

# Force the C locale so "." is the decimal separator everywhere (docker stats emits
# dotted numbers; a comma-locale awk would mis-parse and string-compare them).
export LC_ALL=C

cd "$(dirname "$0")/../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18080}
ROUTE=${ROUTE:-/all}
DURATION=${DURATION:-20}
CONNECTIONS=${CONNECTIONS:-256}
WRK_THREADS=${WRK_THREADS:-4}
MAXCONCURRENCY=${MAXCONCURRENCY:-0}
SAMPLE_INTERVAL=${SAMPLE_INTERVAL:-2}

EXTENSION=/sconcur/ext/build/sconcur.so
SCRIPT=/sconcur/tests/servers/http/http-server.php
PIDFILE=/tmp/sc-http-load-$PORT.pids
STDERRLOG=/tmp/sc-http-load-$PORT.err

command -v wrk >/dev/null || { echo "wrk not found on host (apt-get install wrk)"; exit 1; }

CORES=$(nproc)
: "${SERVERS:=$(( CORES - WRK_THREADS ))}"
(( SERVERS >= 1 )) || SERVERS=1

if (( SERVERS < CORES )); then
    WRK_CPULIST="${SERVERS}-$(( CORES - 1 ))"
else
    WRK_CPULIST="$(( CORES - WRK_THREADS ))-$(( CORES - 1 ))"
fi

PHP_CID=$($DOCKER_COMPOSE ps -q php)
[ -n "$PHP_CID" ] || { echo "php container is not running (make up)"; exit 1; }
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$PHP_CID")
[ -n "$IP" ] || { echo "could not resolve php container IP"; exit 1; }

# Backend containers to watch (best-effort: skip any that is not running).
MONGO_CID=$($DOCKER_COMPOSE ps -q mongodb 2>/dev/null || true)
MYSQL_CID=$($DOCKER_COMPOSE ps -q mysql 2>/dev/null || true)
PG_CID=$($DOCKER_COMPOSE ps -q postgres 2>/dev/null || true)

# name<TAB>cid pairs for the containers we sample.
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
echo " All-features load + resource benchmark"
echo "   host cores      : $CORES"
echo "   server procs    : $SERVERS  (pinned to cores 0-$(( SERVERS - 1 )), reusePort)"
echo "   wrk threads     : $WRK_THREADS (pinned to cores $WRK_CPULIST)"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s   (sampling every ${SAMPLE_INTERVAL}s)"
echo "   route           : $ROUTE  (fans out across all I/O features)"
echo "   target          : http://$IP:$PORT$ROUTE  (container bridge IP, no NAT)"
echo "=================================================================="

stop_servers

# Spawn one server per core (synchronous exec so the pidfile is fully written).
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

# Wait until /all answers (the lazy feature init + backend connects happen here).
ready=0
for _ in $(seq 1 150); do
    if curl -fsS -o /dev/null --max-time 3 "http://$IP:$PORT$ROUTE" 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done
if (( ready != 1 )); then
    echo "servers did not become reachable / $ROUTE did not answer on $IP:$PORT" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    exit 1
fi
echo "servers up; $ROUTE answers. starting load + sampling..."
echo

SAMPLES=$(mktemp)
RSS=$(mktemp)
trap 'rm -f "$SAMPLES" "$RSS"; stop_servers' EXIT

# Background sampler: container CPU%/MEM (one docker stats call covers all) + summed
# worker RSS, until the wrk run signals done via the marker file.
MARKER=$(mktemp)
(
    while [ -f "$MARKER" ]; do
        docker stats --no-stream --format '{{.ID}} {{.CPUPerc}} {{.MemUsage}}' $WATCH_CIDS 2>/dev/null >> "$SAMPLES" || true
        $DOCKER_COMPOSE exec -T php sh -c '
            total=0
            while read -r pid; do
                kb=$(awk "/^VmRSS:/{print \$2}" "/proc/$pid/status" 2>/dev/null)
                [ -n "$kb" ] && total=$(( total + kb ))
            done < "'"$PIDFILE"'"
            echo "$total"
        ' 2>/dev/null >> "$RSS" || true
        sleep "$SAMPLE_INTERVAL"
    done
) &
SAMPLER=$!

taskset -c "$WRK_CPULIST" wrk -t"$WRK_THREADS" -c"$CONNECTIONS" -d"${DURATION}s" --latency "http://$IP:$PORT$ROUTE"

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
        # Exact id match + a valid "NN.N%" cpu field, so a partial/garbled docker
        # stats line (slow or interrupted call) never corrupts the stats. "+0"
        # forces numeric comparison (no string-compare surprises).
        $1 == cid && $2 ~ /^[0-9.]+%$/ {
            cpu = $2 + 0;
            n++; sum += cpu; if (cpu > peak) peak = cpu;
            # MemUsage like "12.3MiB / 1.5GiB": take the used value (field 3).
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
awk '
    { n++; v[n] = $1; if ($1 > peak) peak = $1 }
    END {
        if (n == 0) { print "  (no samples)"; exit }
        printf "  first: %8.1f MiB\n", v[1]/1024;
        printf "  peak : %8.1f MiB\n", peak/1024;
        printf "  last : %8.1f MiB\n", v[n]/1024;
        printf "  drift: %+.1f MiB (last - first)\n", (v[n]-v[1])/1024;
    }
' "$RSS"

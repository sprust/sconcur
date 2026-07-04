#!/usr/bin/env bash
#
# RoadRunner counterpart of http-load-stats.sh: the same load + resource harness,
# but the server is the RoadRunner reference stack (tests/servers/roadrunner —
# native drivers, no SConcur) instead of the SConcur demo-server pool. Starts one
# `rr serve` managing WORKERS php workers in the `php` container, drives it with
# wrk on the same route (/all by default: usleep + MongoDB insert/findOne +
# MySQL/PostgreSQL INSERT + SELECT 1, sequentially per request), and samples
# CPU/memory of the server and backend containers throughout, plus the summed
# RSS of the php worker processes (leak check).
#
# Same honesty rules as http-load-stats.sh: the rr process (workers inherit its
# affinity) and the load generator are pinned to disjoint cores (taskset), and
# wrk hits the container bridge IP directly (no NAT). Numbers are directly
# comparable with a http-load-stats.sh run at the same WORKERS/SERVERS count.
#
# Run from the HOST (wrk + docker live there); the server runs in the container.
#
# Usage:
#   tests/benchmarks/rr-load-stats.sh
#   WORKERS=8 CONNECTIONS=256 DURATION=20 tests/benchmarks/rr-load-stats.sh
#
# Tunables (env): WORKERS, WRK_THREADS, CONNECTIONS, DURATION, PORT, ROUTE (=/all),
#   SAMPLE_INTERVAL (resource-sampling period, s), MODE (=soak for the long run).
set -euo pipefail

# Force the C locale so "." is the decimal separator everywhere (docker stats emits
# dotted numbers; a comma-locale awk would mis-parse and string-compare them).
export LC_ALL=C

cd "$(dirname "$0")/../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18081}
ROUTE=${ROUTE:-/all}
# MODE=soak: a long, steady-load run that prints the worker-RSS trend over time and a
# least-squares slope, to surface a slow memory leak the short run cannot. It only
# changes the DURATION/SAMPLE_INTERVAL/CONNECTIONS defaults (still overridable).
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

WRK_THREADS=${WRK_THREADS:-4}

RR_CONFIG=/sconcur/tests/servers/roadrunner/.rr.yaml
PIDFILE=/tmp/sc-rr-load-$PORT.pid
STDERRLOG=/tmp/sc-rr-load-$PORT.err

command -v wrk >/dev/null || { echo "wrk not found on host (apt-get install wrk)"; exit 1; }

CORES=$(nproc)
# One php worker per server core, like the SConcur pool (SERVERS accepted as an
# alias so both harnesses can be driven with the same variable).
: "${WORKERS:=${SERVERS:-$(( CORES - WRK_THREADS ))}}"
(( WORKERS >= 1 )) || WORKERS=1

if (( WORKERS < CORES )); then
    RR_CPULIST="0-$(( WORKERS - 1 ))"
    WRK_CPULIST="${WORKERS}-$(( CORES - 1 ))"
else
    RR_CPULIST="0-$(( CORES - 1 ))"
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

stop_server() {
    # Killing the rr process is enough: it terminates its php workers itself.
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        kill "$(cat "'"$PIDFILE"'")" 2>/dev/null || true
        rm -f "'"$PIDFILE"'"
    ' 2>/dev/null || true
}
trap stop_server EXIT

echo "=================================================================="
echo " RoadRunner (native drivers) load + resource benchmark${MODE:+  [$MODE]}"
echo "   host cores      : $CORES"
echo "   php workers     : $WORKERS  (rr pool, pinned to cores $RR_CPULIST)"
echo "   wrk threads     : $WRK_THREADS (pinned to cores $WRK_CPULIST)"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s   (sampling every ${SAMPLE_INTERVAL}s)"
if [ "$ROUTE" = "/all" ]; then
    echo "   route           : $ROUTE  (all I/O features, native, sequential)"
else
    echo "   route           : $ROUTE"
fi
echo "   target          : http://$IP:$PORT$ROUTE  (container bridge IP, no NAT)"
echo "=================================================================="

stop_server

# A stale server on the port would silently absorb the load (the readiness check
# below cannot tell instances apart), so refuse to start over one.
if curl -fsS -o /dev/null --max-time 2 "http://$IP:$PORT/" 2>/dev/null; then
    echo "something already answers on $IP:$PORT — stop it first (stale rr?)" >&2
    exit 1
fi

# Start one rr process; its php workers inherit the affinity mask. RR_HTTP_PORT /
# RR_NUM_WORKERS are expanded by the .rr.yaml itself.
$DOCKER_COMPOSE exec -T php sh -c '
    : > "'"$STDERRLOG"'"
    cd /sconcur
    RR_HTTP_PORT='"$PORT"' RR_NUM_WORKERS='"$WORKERS"' \
        taskset -c '"$RR_CPULIST"' rr serve -c '"$RR_CONFIG"' \
        >/dev/null 2>>"'"$STDERRLOG"'" &
    echo $! > "'"$PIDFILE"'"
'

# Wait until the route answers (worker spawn + lazy backend connects happen here).
ready=0
for _ in $(seq 1 150); do
    if curl -fsS -o /dev/null --max-time 3 "http://$IP:$PORT$ROUTE" 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done
if (( ready != 1 )); then
    echo "rr did not become reachable / $ROUTE did not answer on $IP:$PORT" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    exit 1
fi
echo "rr up; $ROUTE answers. starting load + sampling..."
echo

SAMPLES=$(mktemp)
RSS=$(mktemp)
trap 'rm -f "$SAMPLES" "$RSS"; stop_server' EXIT

# Background sampler: container CPU%/MEM (one docker stats call covers all) + summed
# RSS of the php worker processes (recorded as "elapsed_seconds total_kb" for the
# soak trend), until the wrk run signals done via the marker file. Workers are
# re-discovered from /proc each sample (rr may respawn them; procps is not
# installed in the container, so no pgrep).
MARKER=$(mktemp)
SAMPLE_START=$(date +%s)
(
    while [ -f "$MARKER" ]; do
        docker stats --no-stream --format '{{.ID}} {{.CPUPerc}} {{.MemUsage}}' $WATCH_CIDS 2>/dev/null >> "$SAMPLES" || true
        elapsed=$(( $(date +%s) - SAMPLE_START ))
        total_kb=$($DOCKER_COMPOSE exec -T php sh -c '
            total=0
            for d in /proc/[0-9]*; do
                if tr "\0" " " < "$d/cmdline" 2>/dev/null | grep -q "rr-worker.php"; then
                    kb=$(awk "/^VmRSS:/{print \$2}" "$d/status" 2>/dev/null)
                    [ -n "$kb" ] && total=$(( total + kb ))
                fi
            done
            echo "$total"
        ' 2>/dev/null | tr -d "[:space:]") || true
        [ -n "$total_kb" ] && echo "$elapsed $total_kb" >> "$RSS"
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
echo " Worker RSS (sum across $WORKERS php workers) — leak check"
echo "------------------------------------------------------------------"
# RSS samples are "elapsed_seconds total_kb". Report first/peak/last/drift plus a
# least-squares slope (MiB/min) over the run; in soak mode also dump the full trend.
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
            slope = (n * sty - st * sy) / denom * 60;  # MiB per minute
            verdict = (slope > 0.5) ? "рост — возможна утечка" \
                    : (slope < -0.5) ? "снижается (GC/возврат памяти)" \
                    : "стабильно";
            printf "  slope: %+.2f MiB/min  ->  %s\n", slope, verdict;
        }
    }
' "$RSS"

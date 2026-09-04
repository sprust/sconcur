#!/usr/bin/env bash
#
# All-features load + resource benchmark. Spawns N demo HTTP-server processes
# (SO_REUSEPORT, one per core) in the `php` container, drives them with wrk on the
# /all route (which fans out across EVERY async I/O feature — Sleeper, MongoDB,
# MySQL, PostgreSQL, HTTP-client — concurrently per request), and samples CPU/memory
# of the server and backend containers throughout, plus per-worker RSS (leak check).
#
# Same honesty rules as throughput.sh: servers and the load generator are pinned
# to disjoint cores (taskset), and wrk hits the container bridge IP directly (no NAT).
#
# Run from the HOST (wrk + docker live there); servers run in the container.
#
# Usage:
#   tests/benchmarks/http/load-stats.sh
#   SERVERS=8 CONNECTIONS=256 DURATION=20 tests/benchmarks/http/load-stats.sh
#
# Tunables (env): SERVERS, WRK_THREADS, CONNECTIONS, DURATION, PORT, ROUTE (=/all),
#   MAXCONCURRENCY, DB_POOL_SIZE (the /db* pool per process), SAMPLE_INTERVAL
#   (resource-sampling period, s), SERVER_ENV (extra VAR=value
#   assignments for the worker environment, space-separated), SERVER_ARGS (extra
#   --flags appended to the server command line, e.g. --ladder=l1),
#   PIN_SERVERS: 1 = pin each worker to one logical CPU, as throughput.sh does —
#   on an SMT machine that puts a worker's PHP thread and its runtime thread on
#   the same logical CPU, which they are not meant to share;
#   physical = pin each worker to one physical core, meaning the whole sibling
#   pair, so those two threads can run at the same time — this is what "a process
#   per core" usually means and it is the only pinning mode worth deploying;
#   group = confine the whole pool to the same cores but let the scheduler place
#   the workers within them, which is the honest comparison for "should a
#   deployment pin?" because every arm then has the same core budget; 0 = leave
#   them unpinned, which is what the worker master actually does in production
#   and is therefore the only way to see what the extension runtime makes of a
#   machine it thinks it owns.
#   All four modes draw from the same budget, cpu 0..SERVERS-1, so only the
#   placement inside it differs and the arms stay comparable.
set -euo pipefail

# Force the C locale so "." is the decimal separator everywhere (docker stats emits
# dotted numbers; a comma-locale awk would mis-parse and string-compare them).
export LC_ALL=C

cd "$(dirname "$0")/../../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
PORT=${PORT:-18080}
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
PIN_SERVERS=${PIN_SERVERS:-1}
MAXCONCURRENCY=${MAXCONCURRENCY:-0}
# The /db* routes size their per-process MySQL pool from this (the DB connection
# budget is split across the reuse-port pool, so the useful value depends on
# SERVERS — the worker-count ladder in docs/benchmarks.md walks it).
DB_POOL_SIZE=${DB_POOL_SIZE:-9}
# Dispatch experiment. DISTINCT_PORTS=1 binds
# worker i to PORT+1+i without SO_REUSEPORT, so a proxy (nginx) can sit in front
# on PORT itself and balance per request; the readiness probe then goes to the
# first worker, not to PORT. WORKER_LOGS=1 keeps each worker's access log (one
# line per request, with the handling time) in its own file instead of dropping
# it — that is how the per-worker request/latency spread is measured.
DISTINCT_PORTS=${DISTINCT_PORTS:-0}
WORKER_LOGS=${WORKER_LOGS:-0}
# WRK_SCRIPT: a wrk Lua script (host path) for mixed load profiles; the URL still
# selects host/port, the script selects the paths.
WRK_SCRIPT=${WRK_SCRIPT:-}

# Which extension the workers load. Overridable so the same harness can drive an
# alternative build (the Rust core spike) without a second copy of this script;
# the name matches the makefile's, so `make bench-... SCONCUR_EXT=...` reaches
# here too. A path relative to the repo root works as well as an absolute one —
# the workers start with /sconcur as their working directory.
EXTENSION=${SCONCUR_EXT:-/sconcur/ext/build/sconcur.so}
SCRIPT=/sconcur/tests/servers/http/http-server.php
PIDFILE=/tmp/sc-http-load-$PORT.pids
STDERRLOG=/tmp/sc-http-load-$PORT.err
WORKERLOGPREFIX=/tmp/sc-http-load-$PORT-w

command -v wrk >/dev/null || { echo "wrk not found on host (apt-get install wrk)"; exit 1; }
[ -z "$WRK_SCRIPT" ] || [ -f "$WRK_SCRIPT" ] || { echo "WRK_SCRIPT not found: $WRK_SCRIPT"; exit 1; }

CORES=$(nproc)
: "${SERVERS:=$(( CORES - WRK_THREADS ))}"
(( SERVERS >= 1 )) || SERVERS=1

# Overridable: the nginx arm of the dispatch experiment needs a third pinned
# party, so the generator must be confined to cores the proxy does not use.
if [ -z "${WRK_CPULIST:-}" ]; then
    if (( SERVERS < CORES )); then
        WRK_CPULIST="${SERVERS}-$(( CORES - 1 ))"
    else
        WRK_CPULIST="$(( CORES - WRK_THREADS ))-$(( CORES - 1 ))"
    fi
fi

# The port the load generator hits, and the port the readiness probe hits: with a
# proxy in front they are the same (PORT), but the probe must reach a worker
# before the proxy is even involved.
if (( DISTINCT_PORTS == 1 )); then
    PROBE_PORT=$(( PORT + 1 ))
else
    PROBE_PORT=$PORT
fi

PHP_CID=$($DOCKER_COMPOSE ps -q php)
[ -n "$PHP_CID" ] || { echo "php container is not running (make up)"; exit 1; }
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$PHP_CID")
[ -n "$IP" ] || { echo "could not resolve php container IP"; exit 1; }

# Backend containers to watch (best-effort: skip any that is not running).
MONGO_CID=$($DOCKER_COMPOSE ps -q mongodb 2>/dev/null || true)
MYSQL_CID=$($DOCKER_COMPOSE ps -q mysql 2>/dev/null || true)
PG_CID=$($DOCKER_COMPOSE ps -q postgres 2>/dev/null || true)

# WATCH_EXTRA: one more container to sample by name, outside the compose project.
# The dispatch experiment needs it — a proxy in front of the pool is part of the
# system under test, and its CPU belongs in the per-request cost.
EXTRA_CID=""
if [ -n "${WATCH_EXTRA:-}" ]; then
    EXTRA_CID=$(docker ps -q -f "name=^${WATCH_EXTRA}$" 2>/dev/null || true)
fi

# name<TAB>cid pairs for the containers we sample.
WATCH=$(printf 'php\t%s\nmongodb\t%s\nmysql\t%s\npostgres\t%s\n%s\t%s\n' "$PHP_CID" "$MONGO_CID" "$MYSQL_CID" "$PG_CID" "${WATCH_EXTRA:-extra}" "$EXTRA_CID" | awk -F'\t' '$2!=""')
WATCH_CIDS=$(printf '%s\n' "$WATCH" | awk -F'\t' '{print $2}')

stop_servers() {
    $DOCKER_COMPOSE exec -T php sh -c '
        [ -f "'"$PIDFILE"'" ] || exit 0
        while read -r pid; do kill "$pid" 2>/dev/null || true; done < "'"$PIDFILE"'"
        rm -f "'"$PIDFILE"'"
    ' 2>/dev/null || true
}
trap stop_servers EXIT

# The CPU list each worker is pinned to, one entry per worker, space separated —
# empty when PIN_SERVERS leaves them unpinned. Built here rather than inside the
# container because placement IS what the flag is about, and it is worth reading
# in one place instead of as a case arm wedged into the spawn loop.
#
# Every mode draws from the same budget (cpu 0..SERVERS-1), so an arm differs
# from another only in where inside it the workers sit.
build_pin_cpulists() {
    case "$PIN_SERVERS" in
        1)
            # One logical CPU per worker.
            awk -v n="$SERVERS" 'BEGIN { for (i = 0; i < n; i++) printf "%s ", i }'
            ;;
        physical)
            # One physical core per worker: the sibling pair, read from the
            # kernel rather than assumed, because the budget may cross from
            # SMT cores into cores that have no sibling at all (this host has
            # both). With more workers than pairs they wrap, which is what a
            # 12-worker run on six cores does.
            pairs=$(
                cpu=0
                while [ "$cpu" -lt "$SERVERS" ]; do
                    cat "/sys/devices/system/cpu/cpu$cpu/topology/thread_siblings_list" 2>/dev/null \
                        || echo "$cpu"
                    cpu=$(( cpu + 1 ))
                done | awk '!seen[$0]++'
            )

            echo "$pairs" | awk -v n="$SERVERS" '
                { pair[NR] = $0 }
                END { for (i = 0; i < n; i++) printf "%s ", pair[(i % NR) + 1] }
            '
            ;;
        group)
            # The whole pool in the budget; the scheduler places them.
            awk -v n="$SERVERS" -v list="0-$(( SERVERS - 1 ))" \
                'BEGIN { for (i = 0; i < n; i++) printf "%s ", list }'
            ;;
        *)
            ;;
    esac
}

PIN_CPULISTS=$(build_pin_cpulists)

echo "=================================================================="
echo " All-features load + resource benchmark${MODE:+  [$MODE]}"
echo "   host cores      : $CORES"
case "$PIN_SERVERS" in
    1)        placement="one logical CPU each, cores 0-$(( SERVERS - 1 ))" ;;
    physical) placement="one physical core each (sibling pairs), cores 0-$(( SERVERS - 1 ))" ;;
    group)    placement="pool confined to cores 0-$(( SERVERS - 1 )), scheduler places" ;;
    *)        placement="unpinned, as the worker master runs them" ;;
esac

if (( DISTINCT_PORTS == 1 )); then
    echo "   server procs    : $SERVERS  (ports $(( PORT + 1 ))-$(( PORT + SERVERS )), no reusePort)"
else
    echo "   server procs    : $SERVERS  (reusePort)"
fi
echo "   placement       : $placement  (PIN_SERVERS=$PIN_SERVERS)"
echo "   wrk threads     : $WRK_THREADS (pinned to cores $WRK_CPULIST)"
echo "   connections     : $CONNECTIONS"
echo "   duration        : ${DURATION}s   (sampling every ${SAMPLE_INTERVAL}s)"
if [ "$ROUTE" = "/all" ]; then
    echo "   route           : $ROUTE  (fans out across all I/O features)"
else
    echo "   route           : $ROUTE"
fi
echo "   db pool / proc  : $DB_POOL_SIZE  (the /db* routes)"
[ -n "$WRK_SCRIPT" ] && echo "   wrk script      : $WRK_SCRIPT  (mixed profile; ROUTE used only for the readiness probe)"
[ "$WORKER_LOGS" = "1" ] && echo "   worker logs     : ${WORKERLOGPREFIX}<i>.log  (access log per worker)"
echo "   target          : http://$IP:$PORT$ROUTE  (container bridge IP, no NAT)"
echo "=================================================================="

stop_servers

# Spawn one server per core (synchronous exec so the pidfile is fully written).
# With DISTINCT_PORTS each worker gets its own port and no SO_REUSEPORT, so a
# proxy in front decides the placement instead of the kernel.
$DOCKER_COMPOSE exec -T php sh -c '
    : > "'"$PIDFILE"'"
    : > "'"$STDERRLOG"'"
    rm -f "'"$WORKERLOGPREFIX"'"*.log
    i=0
    while [ "$i" -lt "'"$SERVERS"'" ]; do
        port='"$PORT"'
        reuse=1
        if [ "'"$DISTINCT_PORTS"'" = "1" ]; then
            port=$(( '"$PORT"' + 1 + i ))
            reuse=0
        fi

        out=/dev/null
        if [ "'"$WORKER_LOGS"'" = "1" ]; then
            out="'"$WORKERLOGPREFIX"'$i.log"
        fi

        # The i-th entry of the list the host computed, or nothing when the
        # list is empty (PIN_SERVERS=0).
        pin=""
        if [ -n "'"$PIN_CPULISTS"'" ]; then
            set -- '"$PIN_CPULISTS"'
            skip=$i
            while [ "$skip" -gt 0 ]; do shift; skip=$(( skip - 1 )); done
            pin="taskset -c $1"
        fi

        SCONCUR_DB_POOL_SIZE='"$DB_POOL_SIZE"' \
        env '"${SERVER_ENV:-}"' $pin php -d extension='"$EXTENSION"' '"$SCRIPT"' \
            --address=0.0.0.0:$port --reusePort=$reuse --maxConcurrency='"$MAXCONCURRENCY"' \
            '"${SERVER_ARGS:-}"' \
            >"$out" 2>>"'"$STDERRLOG"'" &
        echo $! >> "'"$PIDFILE"'"
        i=$(( i + 1 ))
    done
'

# Wait until /all answers (the lazy feature init + backend connects happen here).
ready=0
for _ in $(seq 1 150); do
    if curl -fsS -o /dev/null --max-time 3 "http://$IP:$PROBE_PORT$ROUTE" 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done
if (( ready != 1 )); then
    echo "servers did not become reachable / $ROUTE did not answer on $IP:$PROBE_PORT" >&2
    $DOCKER_COMPOSE exec -T php sh -c 'tail -n 20 "'"$STDERRLOG"'" 2>/dev/null' >&2 || true
    exit 1
fi
echo "servers up; $ROUTE answers. starting load + sampling..."
echo

SAMPLES=$(mktemp)
RSS=$(mktemp)
trap 'rm -f "$SAMPLES" "$RSS"; stop_servers' EXIT

# Background sampler: container CPU%/MEM (one docker stats call covers all) + summed
# worker RSS (recorded as "elapsed_seconds total_kb" for the soak trend), until the
# wrk run signals done via the marker file.
MARKER=$(mktemp)
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

WRK_ARGS=(-t"$WRK_THREADS" -c"$CONNECTIONS" -d"${DURATION}s" --latency)
[ -n "$WRK_SCRIPT" ] && WRK_ARGS+=(-s "$WRK_SCRIPT")
# wrk drops timed-out requests from the latency distribution, so a profile with a
# slow tail silently reports a prettier p99 than it earned; raise the timeout past
# the expected tail instead of comparing truncated distributions.
[ -n "${WRK_TIMEOUT:-}" ] && WRK_ARGS+=(--timeout "$WRK_TIMEOUT")

taskset -c "$WRK_CPULIST" wrk "${WRK_ARGS[@]}" "http://$IP:$PORT$ROUTE"

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
            verdict = (slope > 0.5) ? "growing — possible leak" \
                    : (slope < -0.5) ? "falling (GC / memory returned)" \
                    : "stable";
            printf "  slope: %+.2f MiB/min  ->  %s\n", slope, verdict;
        }
    }
' "$RSS"

# Per-worker access-log spread (WORKER_LOGS=1) — the balance check of
# Dispatch experiment, phase 0: does the kernel (or the proxy in
# front) place requests evenly, and does one worker's tail differ from the rest.
# FAST_PATH selects which path counts as the cheap one in a mixed profile; every
# other path is counted as "heavy". The percentiles are of the FAST requests
# only — a fast request that got stuck behind a heavy one is exactly the damage
# the dispatch plan claims to fix.
if [ "$WORKER_LOGS" = "1" ]; then
    FAST_PATH=${FAST_PATH:-/}

    echo
    echo "------------------------------------------------------------------"
    echo " Per-worker spread (access log), fast path = $FAST_PATH"
    echo "------------------------------------------------------------------"
    printf '%-8s %10s %8s %11s %11s %11s\n' "worker" "requests" "heavy" "fast p50" "fast p90" "fast p99"

    COUNTS=$(mktemp)
    i=0
    while (( i < SERVERS )); do
        LOG=$($DOCKER_COMPOSE exec -T php sh -c 'cat "'"$WORKERLOGPREFIX"'"'"$i"'".log" 2>/dev/null' || true)

        total=$(printf '%s\n' "$LOG" | awk 'NF' | wc -l)
        heavy=$(printf '%s\n' "$LOG" | awk -v fp="$FAST_PATH" 'NF && $3 != fp' | wc -l)
        pct=$(printf '%s\n' "$LOG" | awk -v fp="$FAST_PATH" 'NF && $3 == fp {print $5 + 0}' | sort -n | awk '
            {a[++n] = $1}
            END {
                if (n == 0) { print "-  -  -"; exit }
                p50 = a[int(n * 0.50) + (int(n * 0.50) < n * 0.50 ? 1 : 0)];
                p90 = a[int(n * 0.90) + (int(n * 0.90) < n * 0.90 ? 1 : 0)];
                p99 = a[int(n * 0.99) + (int(n * 0.99) < n * 0.99 ? 1 : 0)];
                printf "%.2f %.2f %.2f", p50, p90, p99;
            }')

        printf '%-8s %10s %8s %11s %11s %11s\n' "#$i" "$total" "$heavy" $pct
        echo "$total" >> "$COUNTS"
        i=$(( i + 1 ))
    done

    echo
    awk '
        {n++; c[n] = $1 + 0; sum += c[n]}
        END {
            if (n == 0) { print "  (no worker logs)"; exit }
            min = max = c[1];
            for (i = 1; i <= n; i++) { if (c[i] < min) min = c[i]; if (c[i] > max) max = c[i] }
            mean = sum / n;
            for (i = 1; i <= n; i++) { d = c[i] - mean; ss += d * d }
            sd = (n > 1) ? sqrt(ss / (n - 1)) : 0;
            printf "  requests per worker: min %d / mean %.0f / max %d\n", min, mean, max;
            printf "  spread: max/min = %.2fx, CV = %.1f%%  ->  %s\n",
                (min > 0 ? max / min : 0), (mean > 0 ? 100 * sd / mean : 0),
                ((min > 0 && max / min <= 1.10) ? "even, no imbalance" : "uneven — compare against a proxy");
        }
    ' "$COUNTS"
    rm -f "$COUNTS"
fi

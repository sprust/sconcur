#!/usr/bin/env bash
#
# Attribution ladder, Go core vs Rust core, in one interleaved session.
#
# Rungs (.ai/plans/cpu-per-request-attribution.md, phase 4):
#   L0 — the core's HTTP server answers 200 "ok" itself, PHP is never called
#        (SCONCUR_HTTP_BENCH_L0=1). The floor the runtime gives without PHP.
#   L1 — the same server, but every request crosses to PHP and back: the core
#        streams the request event, the ladder loop answers with a constant
#        respond push. The price of the boundary.
#   l2 — L1 plus a fresh Fiber per request, the push issued off the fiber stack
#        (the production shape).
#   l2f — the same, with the push issued ON the fiber stack. On the Go build
#        this is the documented pathology: a cgo call entered with the stack
#        pointer inside a fiber stack makes glibc re-read /proc/self/maps, four
#        times per fan-out request (hot-path-optimization.md, section 8). Run it
#        against both cores to see whether the mechanism exists at all without
#        cgo — it is the reason Scheduler keeps pendingDispatches.
#
# Both rungs run the SAME PHP script (--ladder=l1) and differ only by that env
# var, so L1 - L0 is the boundary and nothing else. Both cores run the same
# geometry, in the same session, alternating rung by rung, because the delta
# between them is the measurement — absolute numbers drift with whatever else
# the machine is doing.
#
# The metric is microseconds of CPU per request (CPU_avg_percent * 10000 / rps),
# not rps: rps mixes efficiency with saturation.
#
# Usage (from the host, where wrk lives):
#   ext/bench/ladder.sh
#   ROUNDS=5 SERVERS=8 DURATION=20 ext/bench/ladder.sh
set -euo pipefail

export LC_ALL=C

cd "$(dirname "$0")/../.."

ROUNDS=${ROUNDS:-3}
SERVERS=${SERVERS:-6}
WRK_THREADS=${WRK_THREADS:-4}
CONNECTIONS=${CONNECTIONS:-128}
DURATION=${DURATION:-15}
PORT=${PORT:-18080}
# Which rungs to walk. l0/l1 are the gate; l2 (a fresh Fiber, push off the fiber
# stack) and l2f (the same push ON the fiber stack) are the pair that shows
# whether the boundary punishes a crossing from a coroutine stack at all.
RUNGS=${RUNGS:-"l0 l1"}

GO_EXTENSION=/sconcur/ext-go-legacy/build/sconcur.so
RUST_EXTENSION=/sconcur/ext/build/sconcur.so

RESULTS=$(mktemp)
trap 'rm -f "$RESULTS"' EXIT

# One measured run. Prints "rung<TAB>rps<TAB>cpu<TAB>us_per_request".
run_rung() {
    local label=$1 extension=$2 ladder=$3 bench_l0=$4
    local server_env=""

    if [ "$bench_l0" = "1" ]; then
        server_env="SCONCUR_HTTP_BENCH_L0=1"
    fi

    local output
    output=$(
        SERVERS=$SERVERS WRK_THREADS=$WRK_THREADS CONNECTIONS=$CONNECTIONS \
        DURATION=$DURATION ROUTE=/ PORT=$PORT \
        SCONCUR_EXT=$extension SERVER_ARGS="--ladder=$ladder" SERVER_ENV="$server_env" \
        tests/benchmarks/http/load-stats.sh 2>&1
    )

    local rps cpu non2xx
    rps=$(printf '%s\n' "$output" | awk '/^Requests\/sec:/ {print $2}')
    cpu=$(printf '%s\n' "$output" | awk '$1=="php" {print $2; exit}')
    non2xx=$(printf '%s\n' "$output" | awk '/Non-2xx or 3xx responses/ {print $NF}')

    if [ -z "$rps" ] || [ -z "$cpu" ]; then
        echo "  $label: run failed (no rps/cpu in output)" >&2

        return 1
    fi

    if [ -n "${non2xx:-}" ]; then
        echo "  $label: WARNING $non2xx non-2xx responses — the run is not comparable" >&2
    fi

    local micros
    micros=$(awk -v cpu="$cpu" -v rps="$rps" 'BEGIN { printf "%.2f", (cpu * 10000) / rps }')

    printf '%s\t%s\t%s\t%s\n' "$label" "$rps" "$cpu" "$micros" >> "$RESULTS"
    printf '  %-10s rps %-10.0f cpu %-8s %s us/req\n' "$label" "$rps" "$cpu" "$micros"
}

echo "=================================================================="
echo " Attribution ladder: Go core vs Rust core"
echo "   rounds          : $ROUNDS   (medians reported)"
echo "   server procs    : $SERVERS  (pinned, reusePort)"
echo "   wrk             : $WRK_THREADS threads / $CONNECTIONS connections / ${DURATION}s"
echo "=================================================================="

for round in $(seq 1 "$ROUNDS"); do
    echo
    echo "round $round/$ROUNDS"

    for rung in $RUNGS; do
        case $rung in
            l0) ladder=l1; bench_l0=1 ;;
            *)  ladder=$rung; bench_l0=0 ;;
        esac

        run_rung "go-$rung"   "$GO_EXTENSION"   "$ladder" "$bench_l0"
        run_rung "rust-$rung" "$RUST_EXTENSION" "$ladder" "$bench_l0"
    done
done

echo
echo "=================================================================="
echo " Medians over $ROUNDS rounds"
echo "=================================================================="

printf '%-10s %12s %10s %14s\n' "rung" "rps" "cpu avg" "us CPU/req"

median() {
    awk -F'\t' -v rung="$1" -v column="$2" '$1 == rung { print $column }' "$RESULTS" \
        | sort -n \
        | awk '{ values[NR] = $1 } END { if (NR == 0) { print "-" } else if (NR % 2) { print values[(NR + 1) / 2] } else { printf "%.2f", (values[NR / 2] + values[NR / 2 + 1]) / 2 } }'
}

for rung in $RUNGS; do
    for core in go rust; do
        printf '%-10s %12.0f %9s%% %14s\n' \
            "$core-$rung" "$(median "$core-$rung" 2)" "$(median "$core-$rung" 3 | tr -d '%')" "$(median "$core-$rung" 4)"
    done
done

case " $RUNGS " in
    *" l0 "*" l1 "*)
        echo
        echo "deltas (L1 - L0 is the boundary):"

        for core in go rust; do
            l0=$(median "$core-l0" 4)
            l1=$(median "$core-l1" 4)

            awk -v core="$core" -v l0="$l0" -v l1="$l1" \
                'BEGIN { printf "  %-5s L0 %6.2f   boundary %6.2f   L1 total %6.2f  (us CPU/req)\n", core, l0, l1 - l0, l1 }'
        done
        ;;
esac

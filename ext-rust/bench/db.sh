#!/usr/bin/env bash
#
# /db and /db-rw on both cores, interleaved in one session.
#
#   /db     — N sequential point SELECTs per request through the MySQL feature,
#             no WaitGroup: cross-request overlap only.
#   /db-rw  — one INSERT, then COUNT(*), then a point SELECT: a minimal
#             write+read mix.
#
# The numbers in docs/benchmarks.md were taken on another machine and are not
# comparable with anything measured here, which is the whole reason this script
# runs both cores back to back: the delta between them is the result, the
# absolute values belong to this machine and this session only.
#
# Note on /db-rw: every run inserts rows into bench_rw and its COUNT(*) gets
# slower as the table grows, so runs drift downward over a session. Alternating
# the cores run by run is what keeps that drift from landing on one of them.
#
# Usage (from the host, where wrk lives):
#   ext-rust/bench/db.sh
#   ROUNDS=5 SERVERS=8 DURATION=20 ext-rust/bench/db.sh
set -euo pipefail

export LC_ALL=C

cd "$(dirname "$0")/../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}

ROUNDS=${ROUNDS:-3}
SERVERS=${SERVERS:-6}
WRK_THREADS=${WRK_THREADS:-4}
CONNECTIONS=${CONNECTIONS:-128}
DURATION=${DURATION:-15}
PORT=${PORT:-18080}
# 6 workers x 9 keeps the pool inside MySQL's default max_connections of 151.
DB_POOL_SIZE=${DB_POOL_SIZE:-9}
ROUTES=${ROUTES:-"/db /db-rw"}

GO_EXTENSION=/sconcur/ext/build/sconcur.so
RUST_EXTENSION=/sconcur/ext-rust/build/sconcur.so

RESULTS=$(mktemp)
trap 'rm -f "$RESULTS"' EXIT

# /db-rw inserts a row per request and then counts the table, so its throughput
# is a function of how many rows the previous runs left behind. Truncating first
# is what makes two runs comparable at all; without it the session decays
# monotonically and whichever core runs later looks worse.
reset_rw_table() {
    $DOCKER_COMPOSE exec -T mysql sh -c \
        'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
            -e "TRUNCATE TABLE bench_rw" 2>/dev/null' >/dev/null 2>&1 || true
}

run_case() {
    local label=$1 extension=$2 route=$3

    if [ "$route" = "/db-rw" ]; then
        reset_rw_table
    fi

    local output
    output=$(
        SERVERS=$SERVERS WRK_THREADS=$WRK_THREADS CONNECTIONS=$CONNECTIONS \
        DURATION=$DURATION ROUTE="$route" PORT=$PORT DB_POOL_SIZE=$DB_POOL_SIZE \
        SCONCUR_EXT=$extension \
        tests/benchmarks/http/load-stats.sh 2>&1
    )

    local rps cpu p50 p99 non2xx
    rps=$(printf '%s\n' "$output" | awk '/^Requests\/sec:/ {print $2}')
    cpu=$(printf '%s\n' "$output" | awk '$1=="php" {print $2; exit}')
    p50=$(printf '%s\n' "$output" | awk '/^     50%/ {print $2}')
    p99=$(printf '%s\n' "$output" | awk '/^     99%/ {print $2}')
    non2xx=$(printf '%s\n' "$output" | awk '/Non-2xx or 3xx responses/ {print $NF}')

    if [ -z "$rps" ] || [ -z "$cpu" ]; then
        echo "  $label: run failed (no rps/cpu in output)" >&2

        return 1
    fi

    # A run with failed responses did less work than it claims; say so loudly
    # rather than letting it into the median.
    if [ -n "${non2xx:-}" ]; then
        echo "  $label: WARNING $non2xx non-2xx responses — not comparable" >&2
    fi

    local micros
    micros=$(awk -v cpu="$cpu" -v rps="$rps" 'BEGIN { printf "%.1f", (cpu * 10000) / rps }')

    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$label" "$rps" "$cpu" "$micros" "${p50:-?}" "${p99:-?}" >> "$RESULTS"
    printf '  %-14s rps %-9.0f cpu %-8s %8s us/req   p50 %-8s p99 %s\n' \
        "$label" "$rps" "$cpu" "$micros" "${p50:-?}" "${p99:-?}"
}

echo "=================================================================="
echo " DB routes: Go core vs Rust core"
echo "   rounds          : $ROUNDS   (medians reported)"
echo "   server procs    : $SERVERS  (pinned, reusePort), pool $DB_POOL_SIZE/proc"
echo "   wrk             : $WRK_THREADS threads / $CONNECTIONS connections / ${DURATION}s"
echo "   routes          : $ROUTES"
echo "=================================================================="

for round in $(seq 1 "$ROUNDS"); do
    echo
    echo "round $round/$ROUNDS"

    # The order of the two cores alternates by round. /db-rw inserts a row per
    # request and its COUNT(*) gets slower as bench_rw grows, so whichever core
    # runs second in a round is measured against a bigger table. Fixing the
    # order would hand that penalty to the same core every time; alternating
    # splits it evenly and the median stops carrying it.
    for route in $ROUTES; do
        if [ $(( round % 2 )) -eq 1 ]; then
            run_case "go$route"   "$GO_EXTENSION"   "$route"
            run_case "rust$route" "$RUST_EXTENSION" "$route"
        else
            run_case "rust$route" "$RUST_EXTENSION" "$route"
            run_case "go$route"   "$GO_EXTENSION"   "$route"
        fi
    done
done

echo
echo "=================================================================="
echo " Medians over $ROUNDS rounds"
echo "=================================================================="

sort -t"$(printf '\t')" -k1,1 "$RESULTS" | awk -F'\t' '
    { rows[$1] = rows[$1] $2 " " $3 " " $4 " " $5 " " $6 "\n"; }

    function median(values, count,   sorted, index_, inner, swap, half) {
        for (index_ = 1; index_ <= count; index_++) {
            sorted[index_] = values[index_];
        }

        for (index_ = 2; index_ <= count; index_++) {
            for (inner = index_; inner > 1 && sorted[inner - 1] > sorted[inner]; inner--) {
                swap = sorted[inner];
                sorted[inner] = sorted[inner - 1];
                sorted[inner - 1] = swap;
            }
        }

        if (count % 2) {
            return sorted[(count + 1) / 2];
        }

        half = count / 2;

        return (sorted[half] + sorted[half + 1]) / 2;
    }

    END {
        printf "%-14s %11s %9s %13s %10s %10s\n", "case", "rps", "cpu avg", "us CPU/req", "p50", "p99";

        for (label in rows) {
            count = split(rows[label], lines, "\n");
            n = 0;

            for (index_ = 1; index_ <= count; index_++) {
                if (lines[index_] == "") continue;

                split(lines[index_], parts, " ");

                n++;
                rps[n] = parts[1] + 0;
                gsub(/%/, "", parts[2]);
                cpu[n] = parts[2] + 0;
                micros[n] = parts[3] + 0;
                p50[n] = parts[4];
                p99[n] = parts[5];
            }

            if (n == 0) continue;

            printf "%-14s %11.0f %8.1f%% %13.1f %10s %10s\n", \
                label, median(rps, n), median(cpu, n), median(micros, n), p50[int((n + 1) / 2)], p99[int((n + 1) / 2)];
        }
    }
' 2>/dev/null || {
    echo "summary failed; the per-round lines above are the data"
}

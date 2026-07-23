#!/usr/bin/env bash
#
# Re-measurement harness for the DB benchmarks (MongoDB / MySQL / PostgreSQL).
# Runs every benchmark RUNS times, each run against a cold dataset: the bench
# script itself drops and reseeds its table/collection to DATASET rows before
# measuring, so runs are fully independent. Per-run results are collected into
# OUT_DIR/<bench>.csv and aggregated (median/min/max per mode + async-vs-native
# percentages) by tests/benchmarks/_db_runs_aggregate.php into markdown rows for
# docs/benchmarks.md.
#
# Run from the HOST (docker lives there); the benchmarks run in the `php`
# container. DB data should live on disk (named volumes) for a benchmark
# session — see the storage note in docker-compose.yml — and start from a clean
# state (`make bench-reset`).
#
# Usage:
#   tests/benchmarks/db-bench-runs.sh                 # all DB benches
#   tests/benchmarks/db-bench-runs.sh mysql-insert    # a subset (uses default calls)
#   RUNS=2 DATASET=1000 tests/benchmarks/db-bench-runs.sh   # smoke run
#
# Tunables (env): RUNS (=5), DATASET (=100000), OUT_DIR (=.bench-runs).
set -euo pipefail

export LC_ALL=C

cd "$(dirname "$0")/../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
RUNS=${RUNS:-5}
DATASET=${DATASET:-100000}
OUT_DIR=${OUT_DIR:-.bench-runs}

# bench:calls — the per-mode call count. Most benches run 100 calls; the
# exceptions are bounded by the operation's nature: createIndex by MongoDB's
# 64-indexes-per-collection limit (3 modes x 20 fits), updateMany rewrites the
# whole seeded dataset per call, bulkWrite scans it several times per call.
DEFAULT_BENCHES=(
    mongodb-insert-one:100
    mongodb-insert-many:100
    mongodb-bulk-write:20
    mongodb-aggregate:100
    mongodb-count:100
    mongodb-update-one:100
    mongodb-find-one:100
    mongodb-create-index:20
    mongodb-delete-one:100
    mongodb-update-many:10
    mongodb-command:100
    mysql-insert:100
    mysql-select-one:100
    mysql-select-many:100
    mysql-count:100
    mysql-update:100
    mysql-delete:100
    mysql-transaction:100
    pgsql-insert:100
    pgsql-select-one:100
    pgsql-select-many:100
    pgsql-count:100
    pgsql-update:100
    pgsql-delete:100
    pgsql-transaction:100
)

BENCHES=("${DEFAULT_BENCHES[@]}")

if [ "$#" -gt 0 ]; then
    BENCHES=("$@")
fi

mkdir -p "$OUT_DIR"

session_started=$(date +%s)

for entry in "${BENCHES[@]}"; do
    bench=${entry%%:*}
    calls=${entry##*:}

    if [ "$calls" = "$bench" ]; then
        calls=100
    fi

    csv="$OUT_DIR/$bench.csv"
    : > "$csv"

    for run in $(seq 1 "$RUNS"); do
        run_started=$(date +%s)

        output=$($DOCKER_COMPOSE exec -T -e SCONCUR_BENCH_DATASET="$DATASET" php \
            php -d extension=./ext/build/sconcur.so "tests/benchmarks/$bench.php" "$calls")

        times=$(printf '%s\n' "$output" | awk -F'\t' '/^Total time/{value=$2} END{print value}')
        memories=$(printf '%s\n' "$output" | awk -F'\t' '/^Mem peak/{value=$2} END{print value}')

        if [ -z "$times" ]; then
            echo "ERROR: no result line from $bench (run $run)" >&2
            printf '%s\n' "$output" >&2
            exit 1
        fi

        echo "$calls;$times;$memories" >> "$csv"
        echo "[$(date +%H:%M:%S)] $bench run $run/$RUNS: $times s ($(($(date +%s) - run_started))s)"
    done
done

echo "Session took $(( ($(date +%s) - session_started) / 60 )) min. Aggregating..."

$DOCKER_COMPOSE exec -T php php "tests/benchmarks/_db_runs_aggregate.php" "$OUT_DIR"

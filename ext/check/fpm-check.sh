#!/usr/bin/env bash
#
# Does the core work under PHP-FPM?
#
# FPM is the shape the Go build cannot take: the extension is loaded in the
# master at MINIT, and every worker that actually serves a request is a process
# forked from it afterwards. This starts a real php-fpm pool (4 static workers)
# with the given extension and drives requests straight at it with cgi-fcgi —
# no HTTP server in front, nothing to blame but the SAPI.
#
# Each request runs a fan-out of twelve 100ms coroutines and reports OK only if
# they ran concurrently, so a worker whose runtime did not survive the fork
# fails loudly instead of quietly serializing.
#
# Usage (from the host):
#   ext/check/fpm-check.sh                     # the Rust core
#   ext/check/fpm-check.sh go                  # the Go core, as the control
set -uo pipefail

cd "$(dirname "$0")/../.."

CORE=${1:-rust}
REQUESTS=${REQUESTS:-8}
# How long one request may take before it counts as hung. A working core answers
# in ~100ms; a core whose runtime did not survive the fork never answers at all.
REQUEST_TIMEOUT=${REQUEST_TIMEOUT:-20}

case "$CORE" in
    rust) EXTENSION=/sconcur/ext/build/sconcur.so ;;
    go)   EXTENSION=/sconcur/ext-go-legacy/build/sconcur.so ;;
    *)    echo "usage: $0 [rust|go]" >&2; exit 2 ;;
esac

echo "=================================================================="
echo " PHP-FPM check: $CORE core"
echo "   extension : $EXTENSION"
echo "   pool      : 4 static workers, $REQUESTS requests"
echo "=================================================================="

docker run --rm \
    -v "$PWD:/sconcur" \
    -w /sconcur \
    sconcur-fpm-spike:latest \
    bash -c '
        php-fpm -d extension='"$EXTENSION"' \
            -y /sconcur/ext/check/fpm/www.conf >/tmp/fpm.log 2>&1 &
        fpm_pid=$!

        # Wait for the pool to bind before the first request: probe with the
        # same client the checks use, so readiness means "answers", not "listens".
        for _ in $(seq 1 50); do
            if SCRIPT_FILENAME=/sconcur/ext/check/fpm/ping.php \
               SCRIPT_NAME=/ping.php REQUEST_METHOD=GET \
               timeout 5 cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null 2>&1; then
                break
            fi

            sleep 0.2
        done

        failures=0

        for i in $(seq 1 '"$REQUESTS"'); do
            body=$(
                SCRIPT_FILENAME=/sconcur/ext/check/fpm/index.php \
                SCRIPT_NAME=/index.php \
                REQUEST_METHOD=GET \
                timeout '"$REQUEST_TIMEOUT"' cgi-fcgi -bind -connect 127.0.0.1:9000 2>&1 \
                    | tail -n 1
            )

            echo "  request $i: ${body:-<no response: timed out>}"

            case "$body" in
                OK*) ;;
                *) failures=$(( failures + 1 )) ;;
            esac
        done

        kill "$fpm_pid" 2>/dev/null

        echo
        if [ "$failures" -eq 0 ]; then
            echo "all '"$REQUESTS"' requests served concurrently"
        else
            echo "$failures of '"$REQUESTS"' requests failed"
            echo "--- fpm log (tail) ---"
            tail -n 15 /tmp/fpm.log
        fi

        [ "$failures" -eq 0 ]
    '

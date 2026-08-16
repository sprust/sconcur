#!/usr/bin/env bash
#
# nginx in front of the worker pool — the "someone else already wrote the
# dispatcher" arm of .ai/plans/dispatch-experiment.md.
#
# Runs nginx inside the php container's network namespace, so the hop to the
# workers is loopback rather than the docker bridge: that is the closest honest
# stand-in for the unix socket the dispatch proposal would use, and it does not
# flatter the proxy by adding bridge cost to it either.
#
# The workers must already be up on distinct ports (DISTINCT_PORTS=1 in
# http/load-stats.sh), i.e. PORT+1 .. PORT+SERVERS; nginx itself listens on PORT.
#
# Usage:
#   SERVERS=8 STRATEGY=least_conn tests/benchmarks/http/nginx-proxy.sh start
#   tests/benchmarks/http/nginx-proxy.sh stop
#
# Tunables (env): SERVERS, PORT, STRATEGY (round_robin|least_conn|random2),
#   NGINX_WORKERS, CPUSET, KEEPALIVE, IMAGE.
set -euo pipefail

cd "$(dirname "$0")/../../.."

DOCKER_COMPOSE=${DOCKER_COMPOSE:-docker compose}
SERVERS=${SERVERS:-8}
PORT=${PORT:-18080}
STRATEGY=${STRATEGY:-least_conn}
NGINX_WORKERS=${NGINX_WORKERS:-4}
CPUSET=${CPUSET:-8-11}
KEEPALIVE=${KEEPALIVE:-64}
IMAGE=${IMAGE:-nginx:1.27-alpine}
NAME=sconcur-dispatch-nginx
CONF=/tmp/sconcur-dispatch-nginx.conf

case "${1:-}" in
    stop)
        docker rm -f "$NAME" >/dev/null 2>&1 || true
        echo "nginx stopped"
        exit 0
        ;;
    start) ;;
    *)
        echo "usage: $0 start|stop" >&2
        exit 2
        ;;
esac

# round_robin is nginx's default and is expressed by the absence of a directive.
case "$STRATEGY" in
    round_robin) BALANCER="" ;;
    least_conn)  BALANCER="    least_conn;" ;;
    random2)     BALANCER="    random two least_conn;" ;;
    *) echo "unknown STRATEGY: $STRATEGY (round_robin|least_conn|random2)" >&2; exit 2 ;;
esac

UPSTREAMS=""
i=0
while (( i < SERVERS )); do
    UPSTREAMS+="    server 127.0.0.1:$(( PORT + 1 + i )) max_fails=0;"$'\n'
    i=$(( i + 1 ))
done

cat > "$CONF" <<CONFEOF
# No "daemon off" here — the official image's CMD already passes it as -g, and a
# second one is a duplicate-directive fatal error.
worker_processes $NGINX_WORKERS;
error_log /dev/null crit;
pid /tmp/nginx-dispatch.pid;

events {
    worker_connections 16384;
    multi_accept on;
}

http {
    access_log off;

    # The proxy must not become the thing under test: no logging, no buffering
    # games, keep-alive to the upstream (without proxy_http_version 1.1 and the
    # cleared Connection header nginx would open a fresh TCP connection to a
    # worker on every request, and the measurement would be about connection
    # setup rather than about dispatching).
    upstream workers {
$BALANCER
$UPSTREAMS
        keepalive $KEEPALIVE;
    }

    server {
        listen $PORT reuseport backlog=16384;

        location / {
            proxy_pass http://workers;
            proxy_http_version 1.1;
            proxy_set_header Connection "";
        }
    }
}
CONFEOF

PHP_CID=$($DOCKER_COMPOSE ps -q php)
[ -n "$PHP_CID" ] || { echo "php container is not running (make up)"; exit 1; }

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" \
    --network "container:$PHP_CID" \
    --cpuset-cpus "$CPUSET" \
    -v "$CONF:/etc/nginx/nginx.conf:ro" \
    "$IMAGE" >/dev/null

# Wait for the listener: nginx accepts before any worker exists (the upstreams
# are literal ip:port, so no startup resolution), and a load run that begins
# before the listener is up dies on "connection refused".
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$PHP_CID")
ready=0
for _ in $(seq 1 50); do
    if (exec 3<>"/dev/tcp/$IP/$PORT") 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done

if (( ready != 1 )); then
    echo "nginx did not start listening on $IP:$PORT" >&2
    docker logs "$NAME" 2>&1 | tail -20 >&2
    exit 1
fi

echo "nginx up: strategy=$STRATEGY workers=$SERVERS (ports $(( PORT + 1 ))-$(( PORT + SERVERS ))) listen=$PORT cpuset=$CPUSET"

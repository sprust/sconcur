# PHP-FPM image for the fork/FPM half of the extension: it exists to prove the
# core survives the master forking a worker, and that a request can run several
# operations at once inside one FPM process. Driven by ext/check/fpm-check.sh.
#
# Same php:8.4.15 tag as every other image here, so the extension built in the
# `php` container loads unchanged — only the SAPI differs. Carries cgi-fcgi, which
# drives requests straight at the FPM pool without an HTTP server in front; the
# package needs no PHP extension beyond its own.
#
# Spike-only: not part of docker-compose.yml, not part of `make check`.
FROM php:8.4.15-fpm

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      libfcgi-bin \
      unzip && \
    rm -rf /var/lib/apt/lists/*


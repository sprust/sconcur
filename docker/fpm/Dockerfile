# PHP-FPM image for the fork/FPM half of the Rust core spike
# (.ai/plans/rust-core-spike.md).
#
# Same php:8.4.15 tag as every other image here, so the extension built in the
# `php` container loads unchanged — only the SAPI differs. Carries ext-msgpack
# (the package requires it) and cgi-fcgi, which drives requests straight at the
# FPM pool without an HTTP server in front.
#
# Spike-only: not part of docker-compose.yml, not part of `make check`.
FROM php:8.4.15-fpm

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      libfcgi-bin \
      unzip && \
    rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie

RUN pie install msgpack/msgpack-php:3.0.1

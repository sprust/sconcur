# The image the release workflow builds on: everything `make check` needs and nothing
# else.
#
# The development image (docker/php/Dockerfile) additionally carries the RoadRunner
# binary and a compiled Swoole — the two reference servers the benchmark comparisons in
# docs/benchmarks.md run against. A release never touches them: PHPUnit does not, and
# php-cs-fixer and PHPStan read only src/ and tests/feature, so tests/servers/{swoole,
# roadrunner} is outside every check. The Swoole build alone is the longest step in the
# development image, and supervisor, the RoadRunner download and the headers only those
# two need go with it.
FROM php:8.4.15-cli

WORKDIR /app

# Container user & group. `.env.example` — the file CI copies — sets both to 0: the
# repository is bind-mounted and the build writes ext/build/sconcur.so back to
# the host, which is the artifact the release is made of.
ARG USER_ID
ARG GROUP_ID

# Headers for the extensions built below, and unzip for composer to extract with:
# libpq-dev → pdo_pgsql, libssl-dev → mongodb, librabbitmq-dev → amqp.
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      libpq-dev \
      libssl-dev \
      librabbitmq-dev \
      unzip && \
    rm -rf /var/lib/apt/lists/*

# The Rust toolchain builds the extension (ext/build.sh: cargo produces a
# static archive, gcc links it with the PHP glue), so it is as much a part of
# `make check` as PHP is.
ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    RUST_VERSION="1.98.0"

ENV PATH=/usr/local/cargo/bin:$PATH

RUN curl -fsSL https://sh.rustup.rs -o /tmp/rustup.sh && \
    sh /tmp/rustup.sh -y --no-modify-path --profile minimal --default-toolchain "$RUST_VERSION" && \
    rm /tmp/rustup.sh && \
    chmod -R 777 "$RUSTUP_HOME" "$CARGO_HOME"

RUN mkdir -p /.cache && chmod -R 777 /.cache

RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie

# pcntl — the signal handling of the worker master and of every long-lived worker;
# pdo_mysql and pdo_pgsql — the native baselines the SQL feature tests seed and compare
# against; sockets — a platform requirement of spiral/goridge, which composer pulls in
# with the dev dependencies whether or not RoadRunner is ever run.
RUN docker-php-ext-install pcntl pdo_mysql pdo_pgsql sockets

# msgpack carries every payload across the PHP↔Go boundary and is what the library
# itself requires. mongodb and amqp are test-only: the feature tests compare SConcur
# against the live native extension rather than against literals written down once.
# Pinned exactly, like the composer constraints they mirror.
RUN pie install msgpack/msgpack-php:3.0.1 && \
    pie install mongodb/mongodb-extension:1.21.5 && \
    pie install php-amqp/php-amqp:2.2.0

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"

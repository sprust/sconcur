FROM php:8.4.15-cli

WORKDIR /app

# Container user & group
ARG USER_ID
ARG GROUP_ID

# Install required packages
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      libcurl4-openssl-dev \
      libtool \
      libzip-dev \
      libpq-dev \
      zip \
      unzip \
      libssl-dev \
      librabbitmq-dev \
      supervisor \
      wget && \
    rm -rf /var/lib/apt/lists/*

ENV GO_VERSION="1.26.1"

RUN wget -P /tmp "https://dl.google.com/go/go${GO_VERSION}.linux-amd64.tar.gz"

RUN tar -C /usr/local -xzf "/tmp/go${GO_VERSION}.linux-amd64.tar.gz"
RUN rm "/tmp/go${GO_VERSION}.linux-amd64.tar.gz"

ENV GOPATH=/go
ENV PATH=$GOPATH/bin:/usr/local/go/bin:$PATH
RUN mkdir -p "$GOPATH/src" "$GOPATH/bin" && chmod -R 777 "$GOPATH" && mkdir -p /.cache && chmod -R 777 /.cache

RUN mkdir -p -m 777 /go/pkg/mod/cache/download
RUN chown $USER_ID:$GROUP_ID /go/pkg/mod/cache/download
RUN chmod -R 777 /go/

RUN curl -sS https://getcomposer.org/installer  \
    | php -- --install-dir=/usr/local/bin --filename=composer

# RoadRunner binary — the reference server for the honest native-driver
# comparison (tests/servers/roadrunner, docs/benchmarks.ru.md). Pinned to the
# version the documented comparison ran on.
ENV ROADRUNNER_VERSION="2025.1.15"

RUN wget -P /tmp "https://github.com/roadrunner-server/roadrunner/releases/download/v${ROADRUNNER_VERSION}/roadrunner-${ROADRUNNER_VERSION}-linux-amd64.tar.gz" && \
    tar -C /tmp -xzf "/tmp/roadrunner-${ROADRUNNER_VERSION}-linux-amd64.tar.gz" && \
    mv "/tmp/roadrunner-${ROADRUNNER_VERSION}-linux-amd64/rr" /usr/local/bin/rr && \
    chmod +x /usr/local/bin/rr && \
    rm -rf "/tmp/roadrunner-${ROADRUNNER_VERSION}-linux-amd64" "/tmp/roadrunner-${ROADRUNNER_VERSION}-linux-amd64.tar.gz"

COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie

# mongodb 1.21.0 (the previously pinned version) is blocked by a security
# advisory (PKSA-r3zn-9sn1-sy2d, affects <1.21.2); 1.21.5 is the patched line.
# sockets is required by spiral/roadrunner-worker (the RoadRunner reference server).
RUN docker-php-ext-install zip pcntl pdo_mysql mysqli pdo_pgsql sockets && \
    pie install mongodb/mongodb-extension:1.21.5 && \
    pie install msgpack/msgpack-php:3.0.1

# amqp is required only by the AMQP parity tests: they compare the calque in
# SConcur\Features\Amqp against the live PECL extension instead of against
# literals written down once (docs/amqp.md, tests/feature/Features/Amqp).
# Pinned exactly, like the composer require-dev constraint it mirrors.
RUN pie install php-amqp/php-amqp:2.2.0

# Swoole — the second reference server for the comparison (tests/servers/swoole,
# docs/benchmarks.ru.md). Pinned to the version the documented comparison ran on.
# Built after the bundled extensions above: the coroutine runtime hooks are only
# as complete as the build (sockets/mysqlnd/curl need those headers, pgsql needs
# libpq). Deliberately NOT enabled via docker-php-ext-enable — the test suite and
# every other benchmark must keep running on a stock PHP, so the swoole server
# loads it explicitly with `php -d extension=swoole.so`.
ENV SWOOLE_VERSION="6.2.2"

RUN pecl install --configureoptions '\
      enable-sockets="yes" \
      enable-mysqlnd="yes" \
      enable-swoole-curl="yes" \
      enable-swoole-pgsql="yes" \
      enable-brotli="no"' \
    "swoole-${SWOOLE_VERSION}" && \
    rm -rf /tmp/pear

# Rust toolchain: the core the extension is built from lives in ext/, so
# this is what `make ext-build` needs. It sits beside the Go one — which still
# builds the reference core in ext-go-legacy/ — for the same reason: build.sh compiles
# ext/sconcur.c and links it against the staticlib cargo produces, and both
# halves have to see the same PHP headers this image already carries.
# Placed after the Swoole build so the expensive cached layers above are reused.
ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    RUST_VERSION="1.98.0"

ENV PATH=/usr/local/cargo/bin:$PATH

RUN curl -fsSL https://sh.rustup.rs -o /tmp/rustup.sh && \
    sh /tmp/rustup.sh -y --no-modify-path --profile minimal --default-toolchain "$RUST_VERSION" && \
    rm /tmp/rustup.sh && \
    chmod -R 777 "$RUSTUP_HOME" "$CARGO_HOME"

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"

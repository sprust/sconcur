# The profiling image: the development image with a PHP binary you can read a
# call graph out of. Nothing else differs, and nothing here is in the image the
# numbers are taken with.
#
# The reason it exists: the PHP binary in `php:8.4-cli` is stripped and built
# without frame pointers, so a sampling profiler sees the C stack of the engine
# as a flat wall of unnamed addresses. Two separate plans reached the same halt
# because of it — `.ai/plans/hot-path-optimization.md` item 3, where the ~30 us
# of PSR-7 construction is the largest remaining line and cannot be attributed,
# and `.ai/plans/rust-core-hot-path.md`, where ~6.3 us of the coordination cycle
# no longer divides by differential measurement. Both need one image, so it is
# built once here rather than twice there.
#
# It rebuilds PHP from the sources the base image already carries, with the same
# configure line and two additions: frame pointers, and symbols left in. The
# ABI does not change — same version, same API number, still non-debug non-ZTS —
# so every extension the base image installed keeps working from the same
# extension_dir. `--enable-debug-assertions` from the original recipe is
# deliberately NOT used: it changes ZEND_DEBUG, which does change the ABI, and
# every prebuilt extension in the image would refuse to load.
#
# Build it on top of the development image, which must exist first:
#     make build            # the development image
#     make profile-build    # this one
ARG BASE_IMAGE=sconcur-php-php

FROM ${BASE_IMAGE}

USER root

# perf reads the frame pointers this image exists to provide; it is the Debian
# build, so it may warn that its version does not match the host kernel, and the
# sampling this is for works anyway. Beside it, the headers PHP's own configure
# needs. The base image builds PHP and
# then drops its build dependencies, so they have to come back for the rebuild
# below; the configure line below fails on the first missing one (libxml2 is the
# first it looks for).
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      linux-perf \
      elfutils \
      binutils \
      pkg-config \
      libxml2-dev \
      libsqlite3-dev \
      libonig-dev \
      libreadline-dev \
      libedit-dev \
      libargon2-dev \
      libsodium-dev \
      zlib1g-dev && \
    rm -rf /var/lib/apt/lists/*

# The configure line is the base image's own (php -i, "Configure Command") with
# nothing removed. Keeping it identical is the point: this image must differ from
# the one being profiled in the flags below and in nothing else.
ENV PHP_PROFILING_CFLAGS="-fno-omit-frame-pointer -g -O2 -fstack-protector-strong -fpic -fpie -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64"

RUN docker-php-source extract && \
    cd /usr/src/php && \
    CFLAGS="$PHP_PROFILING_CFLAGS" \
    CPPFLAGS="$PHP_PROFILING_CFLAGS" \
    LDFLAGS="-Wl,-O1 -pie" \
    ./configure \
      --build=x86_64-linux-gnu \
      --with-config-file-path=/usr/local/etc/php \
      --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
      --enable-option-checking=fatal \
      --with-mhash \
      --with-pic \
      --enable-mbstring \
      --enable-mysqlnd \
      --with-password-argon2 \
      --with-sodium=shared \
      --with-pdo-sqlite=/usr \
      --with-sqlite3=/usr \
      --with-curl \
      --with-iconv \
      --with-openssl \
      --with-readline \
      --with-zlib \
      --enable-phpdbg \
      --enable-phpdbg-readline \
      --with-pear \
      --with-libdir=lib/x86_64-linux-gnu \
      --enable-embed && \
    make -j"$(nproc)" && \
    make install && \
    docker-php-source delete

# excimer samples the PHP stack itself, which is the other half of the picture:
# perf says which C function burns the time, excimer says which PHP function was
# on the stack while it did.
#
# Deliberately NOT enabled through docker-php-ext-enable, for the same reason
# Swoole is not in the development image: an always-on profiler would land in
# every row of docs/benchmarks.md. It is loaded per run, by the make targets.
RUN pecl install excimer && rm -rf /tmp/pear

ARG USER_ID
ARG GROUP_ID

USER "$USER_ID"

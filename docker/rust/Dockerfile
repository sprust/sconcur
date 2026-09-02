# Build image for the Rust core spike (.ai/plans/ — spike branch spike/rust-core).
#
# Based on the same php:8.4.15-cli tag the project's images use, so the linked
# sconcur.so is ABI-identical to what the `php` container loads: the PHP headers
# come from the image, the Rust toolchain is added on top, and the final link is
# a plain gcc call against the staticlib cargo produces.
#
# Spike-only: not part of `make check`, not referenced by the release workflow.
FROM php:8.4.15-cli

WORKDIR /sconcur

ARG USER_ID
ARG GROUP_ID

# gcc/make for the sconcur.c compile and the final link; curl+ca-certificates for
# rustup; pkg-config is what the crates' build scripts probe with.
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      build-essential \
      ca-certificates \
      curl \
      pkg-config && \
    rm -rf /var/lib/apt/lists/*

ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    PATH=/usr/local/cargo/bin:$PATH \
    RUST_VERSION=1.98.0

RUN curl -fsSL https://sh.rustup.rs -o /tmp/rustup.sh && \
    sh /tmp/rustup.sh -y --no-modify-path --profile minimal --default-toolchain $RUST_VERSION && \
    rm /tmp/rustup.sh && \
    chmod -R a+w $RUSTUP_HOME $CARGO_HOME

CMD ["sh", "-c", "while sleep 1; do :; done"]

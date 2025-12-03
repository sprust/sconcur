FROM php:8.4.15-cli

WORKDIR /app

# Container user & group
ARG USER_ID
ARG GROUP_ID

# Install required packages
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      wget && \
    rm -rf /var/lib/apt/lists/*

ENV GO_VERSION="1.25.0"

RUN wget -P /tmp "https://dl.google.com/go/go${GO_VERSION}.linux-amd64.tar.gz"

RUN tar -C /usr/local -xzf "/tmp/go${GO_VERSION}.linux-amd64.tar.gz"
RUN rm "/tmp/go${GO_VERSION}.linux-amd64.tar.gz"

ENV GOPATH /go
ENV PATH $GOPATH/bin:/usr/local/go/bin:$PATH
RUN mkdir -p "$GOPATH/src" "$GOPATH/bin" && chmod -R 777 "$GOPATH" && mkdir -p /.cache && chmod -R 777 /.cache

RUN mkdir -p -m 777 /go/pkg/mod/cache/download
RUN chown $USER_ID:$GROUP_ID /go/pkg/mod/cache/download
RUN chmod -R 777 /go/

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"

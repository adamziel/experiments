# Portable image for running the branched-wp e2e test.
# Works on Mac (arm64) and Linux (amd64) via TARGETARCH.
FROM debian:bookworm-slim

ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    PATH="/usr/local/bin:${PATH}" \
    PHP_CONFIG=php-config8.2

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        curl \
        libsqlite3-dev \
        php8.2 \
        php8.2-cli \
        php8.2-dev \
        php8.2-mysql \
        php8.2-sqlite3 \
        pkg-config \
        procps \
        tar \
    && rm -rf /var/lib/apt/lists/*

# Install Dolt for the target architecture
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) DOLT_ARCH=amd64 ;; \
        arm64) DOLT_ARCH=arm64 ;; \
        *) echo "unsupported arch: ${TARGETARCH}"; exit 1 ;; \
    esac; \
    curl -sL "https://github.com/dolthub/dolt/releases/latest/download/dolt-linux-${DOLT_ARCH}.tar.gz" -o /tmp/dolt.tgz; \
    tar -C /tmp -xzf /tmp/dolt.tgz; \
    install -m 0755 /tmp/dolt-linux-${DOLT_ARCH}/bin/dolt /usr/local/bin/dolt; \
    rm -rf /tmp/dolt.tgz /tmp/dolt-linux-${DOLT_ARCH}

# Dolt refuses to run without identity
RUN dolt config --global --add user.name "branched-wp" \
 && dolt config --global --add user.email "branched-wp@example.com"

WORKDIR /app

# Copy source. The host .gitignore excludes wp-src/, *.so, *.db, logs.
COPY . /app/

# Fetch a pinned WordPress into e2e/wp-src/ if it's not already vendored.
RUN set -eux; \
    if [ ! -d /app/e2e/wp-src ]; then \
        curl -sL https://wordpress.org/wordpress-6.5.tar.gz -o /tmp/wp.tgz; \
        tar -C /tmp -xzf /tmp/wp.tgz; \
        mv /tmp/wordpress /app/e2e/wp-src; \
        rm /tmp/wp.tgz; \
    fi

# Build the extension against the distro's PHP 8.2
RUN make

# Default: run the 8-step e2e test
CMD ["bash", "/app/e2e/run_e2e.sh"]

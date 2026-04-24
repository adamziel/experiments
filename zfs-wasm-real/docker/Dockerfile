# Builder image for compiling OpenZFS userland to WebAssembly.
#
# Base: the official Emscripten SDK image, which ships emcc, clang for
# wasm32, node, and python. We add autotools so the ZFS sources are at
# least parseable by their own m4; in practice we drive the build with
# a hand-rolled Makefile and never actually run ./configure, because
# OpenZFS's configure.ac probes Linux kernel headers.

FROM emscripten/emsdk:3.1.64

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        autoconf \
        automake \
        libtool \
        m4 \
        pkg-config \
        gawk \
        bison \
        flex \
        git \
        curl \
        ca-certificates \
        xz-utils \
        bsdmainutils \
        file \
 && rm -rf /var/lib/apt/lists/*

# Some ZFS sources use attr.h / xattr.h surface. We stub those out in
# patches/, but having the headers available lets the preprocessor
# survive the include graph before we get to our stubs.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libattr1-dev \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /work
ENV EMSDK_QUIET=1
ENV PATH=/emsdk:/emsdk/upstream/emscripten:${PATH}

# Sanity check: emcc is on PATH and can emit wasm.
RUN emcc --version | head -1

# Portable by default: detect PHP + SQLite via php-config and pkg-config.
# If those tools are unavailable, fall back to Nix-style dev outputs when present.
#
# Cross-platform: Linux (glibc + musl) and macOS. PHP extensions are named
# `branchfs.so` on every platform, including Darwin — PHP's module loader
# uses that name verbatim regardless of host conventions.

UNAME_S := $(shell uname -s)

# BRANCHFS_BUILTIN=1 means the target PHP already has branchfs statically
# linked in (spc-built release binary). We don't build ext/branchfs.so in
# that mode, so no PHP headers / sqlite dev libs are needed — skip all
# the detection that would otherwise $(error) out on hosts that only
# have the static PHP (NixOS, macOS CI runners where Homebrew PHP isn't
# installed, etc.). Local dev on Linux/macOS with Homebrew/apt PHP keeps
# the detection path.
PHP_EXTRA_INCS  :=

ifneq ($(BRANCHFS_BUILTIN),1)
PHP_CONFIG ?= $(shell command -v php-config 2>/dev/null || command -v php-config8.2 2>/dev/null)
PKG_CONFIG ?= $(shell command -v pkg-config 2>/dev/null)

PHP_DEV_DIR ?= $(firstword $(wildcard /nix/store/*-php-*-dev))
SQLITE_INC  ?= $(firstword $(wildcard /nix/store/*-sqlite-*-dev/include))
SQLITE_LIB  ?= $(firstword $(filter-out /nix/store/*-sqlite-*-dev/lib,$(wildcard /nix/store/*-sqlite-*/lib)))

PHP_INCLUDE_DIR ?= $(if $(PHP_CONFIG),$(shell $(PHP_CONFIG) --include-dir 2>/dev/null))

ifneq ($(strip $(PHP_INCLUDE_DIR)),)
PHP_EXTRA_INCS += -I$(PHP_INCLUDE_DIR) \
                  -I$(PHP_INCLUDE_DIR)/main \
                  -I$(PHP_INCLUDE_DIR)/TSRM \
                  -I$(PHP_INCLUDE_DIR)/Zend \
                  -I$(PHP_INCLUDE_DIR)/ext \
                  -I$(PHP_INCLUDE_DIR)/ext/date/lib
else ifneq ($(strip $(PHP_DEV_DIR)),)
PHP_EXTRA_INCS += -I$(PHP_DEV_DIR)/include/php \
                  -I$(PHP_DEV_DIR)/include/php/main \
                  -I$(PHP_DEV_DIR)/include/php/TSRM \
                  -I$(PHP_DEV_DIR)/include/php/Zend \
                  -I$(PHP_DEV_DIR)/include/php/ext \
                  -I$(PHP_DEV_DIR)/include/php/ext/date/lib
else
$(error Could not determine PHP headers. Install php-config or set PHP_DEV_DIR (or pass BRANCHFS_BUILTIN=1 if building against a PHP that already has branchfs statically linked))
endif
endif

SQLITE_CFLAGS ?= $(if $(PKG_CONFIG),$(shell $(PKG_CONFIG) --cflags sqlite3 2>/dev/null))
SQLITE_LIBS   ?= $(if $(PKG_CONFIG),$(shell $(PKG_CONFIG) --libs sqlite3 2>/dev/null))

ifneq ($(strip $(SQLITE_INC)),)
SQLITE_CFLAGS := -I$(SQLITE_INC)
endif
ifneq ($(strip $(SQLITE_LIB)),)
SQLITE_LIBS := -L$(SQLITE_LIB) -lsqlite3 -Wl,-rpath,$(SQLITE_LIB)
endif

ifeq ($(strip $(SQLITE_LIBS)),)
SQLITE_LIBS := -lsqlite3
endif

# macOS: Homebrew ships sqlite3 headers at $(brew --prefix sqlite3)/include.
# pkg-config picks those up when PKG_CONFIG_PATH is set; otherwise fall back
# to the standard Homebrew layout so the build works out of the box.
ifeq ($(UNAME_S),Darwin)
BREW_SQLITE_PREFIX ?= $(shell brew --prefix sqlite3 2>/dev/null)
ifneq ($(strip $(BREW_SQLITE_PREFIX)),)
ifeq ($(strip $(SQLITE_CFLAGS)),)
SQLITE_CFLAGS := -I$(BREW_SQLITE_PREFIX)/include
endif
ifeq ($(strip $(SQLITE_LIBS)),-lsqlite3)
SQLITE_LIBS := -L$(BREW_SQLITE_PREFIX)/lib -lsqlite3
endif
endif
endif

CC      ?= gcc

# Platform-specific link flags. PHP extensions are loaded into the PHP
# binary at runtime, so all Zend/PHP symbols are "undefined" at link time
# and must be resolved from the host process.
#   - Linux: the dynamic linker resolves undefined symbols from the main
#     executable by default (RTLD_GLOBAL behavior for -rdynamic binaries),
#     so `-shared` alone is enough.
#   - macOS: ld64 is strict; we need `-undefined dynamic_lookup` to defer
#     resolution of PHP symbols to load time. We also use `-bundle` instead
#     of `-dynamiclib` — PHP's DL loader uses dlopen(), which accepts
#     either, but `-bundle` is conventionally what `phpize` emits on Darwin.
ifeq ($(UNAME_S),Darwin)
SHLIB_FLAGS := -bundle -undefined dynamic_lookup
else
SHLIB_FLAGS := -shared
endif

CFLAGS  := -fPIC $(SHLIB_FLAGS) -O2 -Wall -DCOMPILE_DL_BRANCHFS -DHAVE_CONFIG_H=0 $(SQLITE_CFLAGS)
INCLUDES := $(PHP_EXTRA_INCS)
LDFLAGS := $(SQLITE_LIBS)
RUSTUP ?= $(shell command -v rustup 2>/dev/null)

# Default Rust target follows the host OS + arch so `make gitpress` works
# out of the box on all four supported CI targets.
UNAME_M := $(shell uname -m)
ifeq ($(UNAME_S),Darwin)
    ifeq ($(UNAME_M),arm64)
        DEFAULT_GITPRESS_TARGET := aarch64-apple-darwin
    else
        DEFAULT_GITPRESS_TARGET := x86_64-apple-darwin
    endif
else
    ifeq ($(UNAME_M),aarch64)
        DEFAULT_GITPRESS_TARGET := aarch64-unknown-linux-musl
    else
        DEFAULT_GITPRESS_TARGET := x86_64-unknown-linux-musl
    endif
endif
GITPRESS_TARGET ?= $(DEFAULT_GITPRESS_TARGET)

.PHONY: all clean test test-compat init-db test-all gitpress

# BRANCHFS_BUILTIN=1 means the `php` we run already has branchfs
# statically linked in (release path: static-php-cli builds branchfs
# into the PHP binary). Skip the `-d extension=...` flag (PHP would
# refuse to re-load an already-loaded module) and drop the
# ext/branchfs.so build dep (there's no separate .so in that mode).
# Local dev with Homebrew/apt PHP keeps the .so path unchanged.
ifeq ($(BRANCHFS_BUILTIN),1)
BRANCHFS_LOAD     :=
BRANCHFS_TEST_DEP :=
else
BRANCHFS_LOAD     := -d extension=$(CURDIR)/ext/branchfs.so
BRANCHFS_TEST_DEP := ext/branchfs.so
endif

all: ext/branchfs.so

ext/branchfs.so: ext/branchfs.c ext/branchfs.h
	$(CC) $(CFLAGS) $(INCLUDES) -o $@ ext/branchfs.c $(LDFLAGS)

init-db: $(BRANCHFS_TEST_DEP)
	php $(BRANCHFS_LOAD) scripts/init_db.php

test: $(BRANCHFS_TEST_DEP)
	php $(BRANCHFS_LOAD) tests/test_basic.php

test-compat: $(BRANCHFS_TEST_DEP)
	php $(BRANCHFS_LOAD) tests/test_plugin_compat.php

test-all: $(BRANCHFS_TEST_DEP)
	php $(BRANCHFS_LOAD) tests/test_basic.php
	php $(BRANCHFS_LOAD) tests/test_plugin_compat.php
	php $(BRANCHFS_LOAD) tests/test_wp_boot.php
	php $(BRANCHFS_LOAD) tests/test_realpath.php
	php $(BRANCHFS_LOAD) tests/test_syscall_overrides.php
	php $(BRANCHFS_LOAD) tests/test_opcache_keys.php
	php $(BRANCHFS_LOAD) tests/test_merge.php
	php $(BRANCHFS_LOAD) tests/test_gc.php
	php $(BRANCHFS_LOAD) tests/test_push_auth.php

clean:
	rm -f ext/branchfs.so /tmp/branchfs_test*.db /tmp/branchfs_wp*.db

gitpress:
	@if [ -n "$(RUSTUP)" ]; then $(RUSTUP) target add $(GITPRESS_TARGET); fi
	cargo build --release --target $(GITPRESS_TARGET) -p gitpress

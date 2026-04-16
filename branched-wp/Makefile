# Portable by default: detect PHP + SQLite via php-config and pkg-config.
# Override PHP_DEV_DIR / SQLITE_INC / SQLITE_LIB on the command line if needed.

PHP_CONFIG ?= php-config
PKG_CONFIG ?= pkg-config

PHP_INCLUDE_DIR ?= $(shell $(PHP_CONFIG) --include-dir)
PHP_EXTRA_INCS  := -I$(PHP_INCLUDE_DIR) \
                   -I$(PHP_INCLUDE_DIR)/main \
                   -I$(PHP_INCLUDE_DIR)/TSRM \
                   -I$(PHP_INCLUDE_DIR)/Zend \
                   -I$(PHP_INCLUDE_DIR)/ext \
                   -I$(PHP_INCLUDE_DIR)/ext/date/lib

SQLITE_CFLAGS ?= $(shell $(PKG_CONFIG) --cflags sqlite3 2>/dev/null)
SQLITE_LIBS   ?= $(shell $(PKG_CONFIG) --libs sqlite3 2>/dev/null)

# Fallback if pkg-config has no sqlite3.pc
ifeq ($(strip $(SQLITE_LIBS)),)
SQLITE_LIBS := -lsqlite3
endif

# Nix-style override: setting PHP_DEV_DIR / SQLITE_INC / SQLITE_LIB still works
ifdef PHP_DEV_DIR
PHP_EXTRA_INCS := -I$(PHP_DEV_DIR)/include/php \
                  -I$(PHP_DEV_DIR)/include/php/main \
                  -I$(PHP_DEV_DIR)/include/php/TSRM \
                  -I$(PHP_DEV_DIR)/include/php/Zend \
                  -I$(PHP_DEV_DIR)/include/php/ext \
                  -I$(PHP_DEV_DIR)/include/php/ext/date/lib
endif
ifdef SQLITE_INC
SQLITE_CFLAGS := -I$(SQLITE_INC)
endif
ifdef SQLITE_LIB
SQLITE_LIBS := -L$(SQLITE_LIB) -lsqlite3 -Wl,-rpath,$(SQLITE_LIB)
endif

CC      ?= gcc
CFLAGS  := -fPIC -shared -O2 -Wall -DCOMPILE_DL_BRANCHFS -DHAVE_CONFIG_H=0 $(SQLITE_CFLAGS)
INCLUDES := $(PHP_EXTRA_INCS)
LDFLAGS := $(SQLITE_LIBS)

.PHONY: all clean test test-compat init-db test-all

all: ext/branchfs.so

ext/branchfs.so: ext/branchfs.c ext/branchfs.h
	$(CC) $(CFLAGS) $(INCLUDES) -o $@ ext/branchfs.c $(LDFLAGS)

init-db: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" scripts/init_db.php

test: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_basic.php

test-compat: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_plugin_compat.php

test-all: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_basic.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_plugin_compat.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_wp_boot.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_realpath.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_syscall_overrides.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_opcache_keys.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_merge.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_gc.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_push_auth.php

clean:
	rm -f ext/branchfs.so /tmp/branchfs_test*.db /tmp/branchfs_wp*.db

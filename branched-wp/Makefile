PHP_DEV_DIR := /nix/store/d2blqhg7hmpsyj91bjl9bkphdksfwidw-php-8.2.29-dev
SQLITE_INC  := /nix/store/x8r21k5xskd7647wzb625g3gfxrj97w8-sqlite-3.48.0-dev/include
SQLITE_LIB  := /nix/store/ks3slx52c0jmfgg717n0jq9lp354mlnw-sqlite-3.48.0/lib

PHP_INCLUDES := -I$(PHP_DEV_DIR)/include/php \
                -I$(PHP_DEV_DIR)/include/php/main \
                -I$(PHP_DEV_DIR)/include/php/TSRM \
                -I$(PHP_DEV_DIR)/include/php/Zend \
                -I$(PHP_DEV_DIR)/include/php/ext \
                -I$(PHP_DEV_DIR)/include/php/ext/date/lib \
                -I$(SQLITE_INC)

CC      := gcc
CFLAGS  := -fPIC -shared -O2 -Wall -DCOMPILE_DL_BRANCHFS -DHAVE_CONFIG_H=0
LDFLAGS := -L$(SQLITE_LIB) -lsqlite3 -Wl,-rpath,$(SQLITE_LIB)

.PHONY: all clean test test-compat init-db

all: ext/branchfs.so

ext/branchfs.so: ext/branchfs.c ext/branchfs.h
	$(CC) $(CFLAGS) $(PHP_INCLUDES) -o $@ ext/branchfs.c $(LDFLAGS)

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

clean:
	rm -f ext/branchfs.so /tmp/branchfs_test*.db /tmp/branchfs_wp*.db

CC ?= gcc
CFLAGS ?= -std=c11 -fPIC -shared -Wall -Wextra -Werror -O2
INCLUDES = -Ivendor/sqlite
BUILD_DIR = build
EXTENSION = $(BUILD_DIR)/sqlite_markdown.so

build: $(EXTENSION)

$(EXTENSION): src/sqlite_markdown.c vendor/sqlite/sqlite3.h vendor/sqlite/sqlite3ext.h
	mkdir -p $(BUILD_DIR)
	$(CC) $(CFLAGS) $(INCLUDES) $< -o $@

test:
	python3 -m unittest discover -s tests -v

clean:
	rm -rf $(BUILD_DIR)

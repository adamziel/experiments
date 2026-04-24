# Full build Makefile for OpenZFS userland to WebAssembly.
#
# Invoked inside the Docker builder image via scripts/docker-run.sh.
# Each stage produces a static archive we then link into the final
# wasm module with our thin zfswasm_* wrapper.
#
#   make libs      — all static archives (libnvpair, libspl, libzpool,
#                    libzfs_core_wasm)
#   make zfswasm   — link the final .wasm + JS glue for Node
#   make clean

ZFS     := upstream/zfs
BUILD   := build
CC      := emcc
AR      := emar

INCS := \
    -I src/config \
    -I src/shim \
    -I $(ZFS)/include \
    -I $(ZFS)/lib/libspl/include \
    -I $(ZFS)/lib/libspl/include/os/linux

DEFS := \
    -D_GNU_SOURCE \
    -DHAVE_ISSETUGID=1 \
    -DHAVE_PTHREAD=1 \
    -D__USE_LARGEFILE64=1

CFLAGS := -O1 -g -fPIC -fvisibility=hidden -pthread $(DEFS) $(INCS) \
          -include src/config/zfs_config.h \
          -include sys/sysmacros.h \
          -Wno-format \
          -Wno-unused-parameter \
          -Wno-unused-function \
          -Wno-unused-variable \
          -Wno-unused-but-set-variable \
          -Wno-type-limits \
          -Wno-implicit-function-declaration \
          -Wno-incompatible-pointer-types \
          -Wno-incompatible-function-pointer-types \
          -Wno-tautological-compare \
          -Wno-sign-compare

# ----- libnvpair ---------------------------------------------------------

NVPAIR_SRCS := \
    $(ZFS)/lib/libnvpair/libnvpair.c \
    $(ZFS)/lib/libnvpair/libnvpair_json.c \
    $(ZFS)/lib/libnvpair/nvpair_alloc_system.c \
    $(ZFS)/module/nvpair/nvpair.c \
    $(ZFS)/module/nvpair/nvpair_alloc_fixed.c \
    $(ZFS)/module/nvpair/fnvpair.c

# ----- libspl ------------------------------------------------------------
# Userland portions we need. The Linux-specific files that touch /proc
# or mount tables are excluded; we replace them with wasm stubs below.

SPL_SRCS := \
    $(ZFS)/lib/libspl/assert.c \
    $(ZFS)/lib/libspl/atomic.c \
    $(ZFS)/lib/libspl/list.c \
    $(ZFS)/lib/libspl/strlcat.c \
    $(ZFS)/lib/libspl/strlcpy.c \
    $(ZFS)/lib/libspl/mkdirp.c \
    $(ZFS)/lib/libspl/page.c \
    $(ZFS)/lib/libspl/timestamp.c \
    $(ZFS)/lib/libspl/getexecname.c

SHIM_SRCS := \
    src/shim/xdr_stub.c \
    src/shim/spl_host_stubs.c

NVPAIR_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(NVPAIR_SRCS))
SPL_OBJS    := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(SPL_SRCS))
SHIM_OBJS   := $(patsubst src/%.c,$(BUILD)/shim/%.o,$(SHIM_SRCS))

.PHONY: libs libnvpair libspl clean
libs: $(BUILD)/libnvpair.a $(BUILD)/libspl.a $(BUILD)/libshim.a
	@echo "libs OK"

libnvpair: $(BUILD)/libnvpair.a
libspl:    $(BUILD)/libspl.a

$(BUILD)/libnvpair.a: $(NVPAIR_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/libspl.a: $(SPL_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/libshim.a: $(SHIM_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/%.o: $(ZFS)/%.c
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

$(BUILD)/shim/%.o: src/%.c
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

clean:
	rm -rf $(BUILD)

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
    -I src/shim/os/wasm/zfs \
    -I $(ZFS)/include \
    -I $(ZFS)/lib/libspl/include \
    -I $(ZFS)/lib/libspl/include/os/linux

# MODE controls the build flavor:
#   debug   — -O1 -g -DDEBUG=1 -DZFS_DEBUG=1, for diagnosing VERIFY()
#             failures or pthread issues.
#   release — -Oz + LTO + no debug symbols + no ZFS internal asserts,
#             optimized for wasm binary size. Default.
MODE ?= release

DEFS_COMMON := \
    -D_GNU_SOURCE \
    -DHAVE_ISSETUGID=1 \
    -DHAVE_PTHREAD=1 \
    -D__USE_LARGEFILE64=1 \
    -DLIB_ZPOOL_BUILD=1

ifeq ($(MODE),debug)
DEFS := $(DEFS_COMMON) -DZFS_DEBUG=1 -DDEBUG=1
OPTFLAGS := -O1 -g
else
DEFS := $(DEFS_COMMON) -DNDEBUG=1
# -Oz: optimize for size over speed; -flto: cross-TU dead code elim so
#      unused ZFS entry points (scrub worker, etc.) drop out of the
#      final wasm even though they live in the same archive.
OPTFLAGS := -Oz -flto
endif

CFLAGS := $(OPTFLAGS) -fPIC -fvisibility=hidden -pthread -sUSE_ZLIB=1 $(DEFS) $(INCS) \
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

# ----- libzpool kernel emulation -----------------------------------------
# kernel.c: provides the kernel-side SPL surface used by module/zfs
#           (taskq, mutex, cv, buf cache shims, kmem, etc).
# taskq.c:  userland taskq implementation (pthreads).
# util.c:   small userland helpers used by ztest/zdb; ZFS also calls some.

LIBZPOOL_SRCS := \
    $(ZFS)/lib/libzpool/kernel.c \
    $(ZFS)/lib/libzpool/taskq.c \
    $(ZFS)/lib/libzpool/util.c

LIBZPOOL_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(LIBZPOOL_SRCS))

# ----- zcommon subset ----------------------------------------------------
ZCOMMON_SRCS := \
    $(ZFS)/module/zcommon/cityhash.c \
    $(ZFS)/module/zcommon/zfeature_common.c \
    $(ZFS)/module/zcommon/zfs_comutil.c \
    $(ZFS)/module/zcommon/zfs_namecheck.c \
    $(ZFS)/module/zcommon/zfs_prop.c \
    $(ZFS)/module/zcommon/zpool_prop.c \
    $(ZFS)/module/zcommon/zprop_common.c \
    $(ZFS)/module/zcommon/zfs_fletcher.c \
    $(ZFS)/module/zcommon/zfs_fletcher_superscalar.c \
    $(ZFS)/module/zcommon/zfs_fletcher_superscalar4.c

ZCOMMON_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(ZCOMMON_SRCS))

# ----- libavl / libunicode / libzutil ------------------------------------
AVL_SRCS := $(ZFS)/module/avl/avl.c
UNICODE_SRCS := $(wildcard $(ZFS)/module/unicode/*.c)
ZUTIL_SRCS := $(wildcard $(ZFS)/lib/libzutil/*.c)

AVL_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(AVL_SRCS))
UNICODE_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(UNICODE_SRCS))
ZUTIL_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(ZUTIL_SRCS))

# ----- module/zfs core (big subset per SUBSET.md) ------------------------
ZFS_SRCS := $(addprefix $(ZFS)/module/zfs/, \
    spa.c spa_misc.c spa_config.c spa_errlog.c spa_history.c \
    spa_stats.c spa_log_spacemap.c spa_checkpoint.c \
    dmu.c dmu_object.c dmu_objset.c dmu_tx.c dmu_zfetch.c \
    dmu_traverse.c \
    dbuf.c dbuf_stats.c dnode.c dnode_sync.c \
    dsl_dir.c dsl_dataset.c dsl_pool.c dsl_synctask.c \
    dsl_destroy.c dsl_prop.c dsl_scan.c dsl_deadlist.c \
    dsl_userhold.c dsl_deleg.c \
    zap.c zap_leaf.c zap_micro.c \
    zio.c zio_checksum.c zio_compress.c zio_inject.c \
    metaslab.c space_map.c space_reftree.c range_tree.c \
    vdev.c vdev_label.c vdev_mirror.c vdev_missing.c \
    vdev_queue.c vdev_indirect.c vdev_indirect_births.c \
    vdev_indirect_mapping.c vdev_initialize.c vdev_trim.c \
    vdev_removal.c vdev_rebuild.c vdev_root.c \
    arc.c aggsum.c btree.c multilist.c rrwlock.c zrlock.c \
    unique.c refcount.c bplist.c bpobj.c bptree.c bqueue.c \
    blkptr.c txg.c uberblock.c fm.c pathname.c \
    zfeature.c zthr.c mmp.c sa.c zil.c zle.c lzjb.c \
    lz4.c lz4_zfs.c \
    zfs_byteswap.c zfs_fm.c zfs_rlock.c zfs_ratelimit.c \
    zfs_sa.c objlist.c abd.c)

ZFS_OS_SRCS := $(addprefix $(ZFS)/module/os/linux/zfs/, \
    vdev_file.c abd_os.c arc_os.c zfs_debug.c zfs_racct.c)

ZFS_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(ZFS_SRCS) $(ZFS_OS_SRCS))

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
    src/shim/spl_host_stubs.c \
    src/shim/disabled_features.c

NVPAIR_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(NVPAIR_SRCS))
SPL_OBJS    := $(patsubst $(ZFS)/%.c,$(BUILD)/%.o,$(SPL_SRCS))
SHIM_OBJS   := $(patsubst src/%.c,$(BUILD)/shim/%.o,$(SHIM_SRCS))

.PHONY: libs libnvpair libspl libzpool libzcommon clean apply-patches
libs: $(BUILD)/libnvpair.a $(BUILD)/libspl.a $(BUILD)/libshim.a $(BUILD)/libzpool.a $(BUILD)/libzcommon.a
	@echo "libs OK"

# Apply our patch queue before any compile. Idempotent.
apply-patches: $(BUILD)/.patches-applied
$(BUILD)/.patches-applied: $(wildcard patches/*.patch) scripts/apply-patches.sh
	@mkdir -p $(BUILD)
	bash scripts/apply-patches.sh
	@touch $@

libnvpair:  $(BUILD)/libnvpair.a
libspl:     $(BUILD)/libspl.a
libzpool:   $(BUILD)/libzpool.a
libzcommon: $(BUILD)/libzcommon.a

$(BUILD)/libzcommon.a: $(ZCOMMON_OBJS)
	$(AR) rcs $@ $^

.PHONY: libzfs zfswasm
libzfs: $(BUILD)/libzfs.a
$(BUILD)/libzfs.a: $(ZFS_OBJS) $(AVL_OBJS) $(UNICODE_OBJS) $(ZUTIL_OBJS)
	$(AR) rcs $@ $^

# ----- final link: zfswasm.wasm + JS glue --------------------------------
# In release mode we also pass the optimizer flags at link time; emcc
# invokes the linker as a second clang pass, so LTO and -Oz both need
# to be present here too to actually take effect.
ZFSWASM_LDFLAGS_COMMON := \
    -Wl,--error-limit=0 \
    -sALLOW_MEMORY_GROWTH=1 \
    -sINITIAL_MEMORY=67108864 \
    -sMODULARIZE=1 \
    -sEXPORT_ES6=1 \
    -sEXPORT_NAME=ZfsWasm \
    -sENVIRONMENT=node,web,worker \
    -sFORCE_FILESYSTEM=1 \
    -sEXPORTED_RUNTIME_METHODS=ccall,cwrap,HEAPU8,HEAPU32,stringToNewUTF8,getValue,setValue,FS \
    -sEXPORTED_FUNCTIONS=_malloc,_free,_zfswasm_poll,_zfswasm_result,_zfswasm_init_begin,_zfswasm_fini_begin,_zfswasm_pool_create_begin,_zfswasm_pool_export_begin,_zfswasm_ds_create_begin,_zfswasm_ds_destroy_begin,_zfswasm_snap_begin,_zfswasm_snap_destroy_begin,_zfswasm_clone_begin,_zfswasm_file_write_begin,_zfswasm_file_read_begin,_main \
    -sUSE_ZLIB=1 \
    -sPTHREAD_POOL_SIZE=32 \
    -sPTHREAD_POOL_SIZE_STRICT=0 \
    -sEXIT_RUNTIME=0 \
    -sPROXY_TO_PTHREAD=1 \
    -sALLOW_BLOCKING_ON_MAIN_THREAD=1 \
    -pthread

ifeq ($(MODE),debug)
ZFSWASM_LDFLAGS := $(ZFSWASM_LDFLAGS_COMMON)
else
# -sWASM_BIGINT=1: drop the legacy 32-bit i64 JS<->wasm marshalling
#   helpers; all targeted browsers + node >= 16 speak BigInt natively.
# We intentionally do NOT pass --closure 1: Closure mangles FS.mkdirTree
#   and other emscripten runtime methods, and the wasm-size win from
#   -Oz + -flto + WASM_BIGINT is already large enough.
ZFSWASM_LDFLAGS := $(ZFSWASM_LDFLAGS_COMMON) \
    -Oz -flto \
    -sWASM_BIGINT=1
endif

zfswasm: $(BUILD)/zfswasm.js

$(BUILD)/zfswasm.o: src/zfswasm.c
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

$(BUILD)/zfswasm.js: $(BUILD)/zfswasm.o $(BUILD)/libzfs.a $(BUILD)/libzpool.a $(BUILD)/libzcommon.a $(BUILD)/libnvpair.a $(BUILD)/libspl.a $(BUILD)/libshim.a
	$(CC) $(CFLAGS) $(ZFSWASM_LDFLAGS) $^ -o $@
	@# emscripten emits .js even with EXPORT_ES6=1; drop a package.json
	@# next to it so Node treats files in build/ as ESM (without it,
	@# Node 18 chokes on `import.meta.url` in the loader). The browser
	@# loads the file by URL and does not care about package.json.
	@printf '{"type":"module"}\n' > $(BUILD)/package.json

$(BUILD)/libnvpair.a: $(NVPAIR_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/libspl.a: $(SPL_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/libshim.a: $(SHIM_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/libzpool.a: $(LIBZPOOL_OBJS)
	$(AR) rcs $@ $^

$(BUILD)/%.o: $(ZFS)/%.c $(BUILD)/.patches-applied
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

$(BUILD)/shim/%.o: src/%.c $(BUILD)/.patches-applied
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

clean:
	rm -rf $(BUILD)

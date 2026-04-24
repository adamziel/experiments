# OpenZFS subset for branched WordPress

We compile OpenZFS userland (the `libzpool` / `ztest` path, not the kernel
module) to WebAssembly. We take only what a branched WordPress demo needs.

## Use case

A single local WordPress install whose filesystem + MySQL data directory sit
on a ZFS dataset. Every "branch" of the site is a ZFS clone of a snapshot.
Writes are cheap (COW), snapshots are O(1), and clones share underlying
blocks until they diverge.

## Hard requirements

1. Create a pool backed by a single regular file (no real block device).
2. Create one dataset per site branch.
3. Read and write POSIX-shaped files on that dataset (the WP docroot + the
   MySQL data directory live here).
4. Snapshot a dataset atomically.
5. Clone a snapshot into a new writable dataset.
6. List snapshots and clones.
7. Destroy snapshots and clones.
8. Export/import the pool cleanly so we can restart without corruption.

## Non-requirements (excluded from the build)

- RAIDZ / dRAID of any flavor. Single-vdev file pool only.
- ZIL SLOG / separate log devices. ZIL is needed but only in its default
  embedded form.
- L2ARC / cache devices.
- Encryption / keystore (`dsl_crypt`, `zio_crypt`, `hkdf`) and the
  entire `libicp` crypto tree.
- Dedup (`ddt_*`, `brt_*`).
- `zfs send` / `zfs recv` / redaction / bookmarks (`dmu_send`, `dmu_recv`,
  `dmu_redact`, `dmu_diff`, bookmark machinery).
- Scrub, resilver. We never replace or repair devices.
- Compression beyond lz4 and lzjb (no zstd, no gzip).
- Checksums beyond fletcher4 (no edonr, skein, blake3 variants; no
  SIMD accelerated fletchers).
- Quotas, user props, Lua channel programs (`zcp_*` + `module/lua/`),
  delegation, `zfs_ioctl.c` paths.
- `zpool upgrade` of feature flags at runtime; we ship a fixed feature
  set.
- `mmap` of file data and `aio_*`. Plain pread/pwrite on the backing
  file.
- `libzfs` / `libzfs_core`. We call DMU/DSL APIs directly.

### How we shrink further: stubs for the cut surfaces

Some files we do compile (e.g. `dsl_dataset.c`) reference symbols
from files we cut (`dsl_crypto_key_unload`, `ddt_class_update`,
`dmu_send_estimate_fast`). We provide tiny stubs in
`src/shim/disabled_features.c` that return `ENOTSUP` for every cut
function. The call paths are never taken at runtime because the
demo never enables encryption/dedup/send, so these stubs are
compile-and-link fillers — not a runtime fallback we depend on.

## Concrete file list

### Compile (from module/zfs)

spa.c, spa_misc.c, spa_config.c, spa_errlog.c, spa_history.c,
spa_stats.c, spa_log_spacemap.c, spa_checkpoint.c,
dmu.c, dmu_object.c, dmu_objset.c, dmu_tx.c, dmu_zfetch.c,
dmu_traverse.c,
dbuf.c, dbuf_stats.c, dnode.c, dnode_sync.c,
dsl_dir.c, dsl_dataset.c, dsl_pool.c, dsl_synctask.c,
dsl_destroy.c, dsl_prop.c, dsl_scan.c, dsl_deadlist.c,
dsl_userhold.c, dsl_deleg.c,
zap.c, zap_leaf.c, zap_micro.c,
zio.c, zio_checksum.c, zio_compress.c, zio_inject.c,
metaslab.c, space_map.c, space_reftree.c, range_tree.c,
vdev.c, vdev_label.c, vdev_mirror.c, vdev_missing.c,
vdev_queue.c, vdev_indirect.c, vdev_indirect_births.c,
vdev_indirect_mapping.c, vdev_initialize.c, vdev_trim.c,
vdev_removal.c, vdev_rebuild.c, vdev_root.c,
arc.c, aggsum.c, btree.c, multilist.c, rrwlock.c, zrlock.c,
unique.c, refcount.c, bplist.c, bpobj.c, bptree.c, bqueue.c,
blkptr.c, txg.c, uberblock.c, fm.c, pathname.c,
zfeature.c, zthr.c, mmp.c, sa.c, zil.c, zle.c, lzjb.c,
lz4.c, lz4_zfs.c,
zfs_byteswap.c, zfs_fm.c, zfs_rlock.c, zfs_ratelimit.c,
zfs_sa.c, objlist.c, abd.c

### Compile (from module/zcommon)

cityhash.c, zfeature_common.c, zfs_comutil.c, zfs_namecheck.c,
zfs_prop.c, zpool_prop.c, zprop_common.c,
zfs_fletcher.c, zfs_fletcher_superscalar.c,
zfs_fletcher_superscalar4.c

### Compile (from module/os/linux/zfs)

vdev_file.c, abd_os.c, arc_os.c, zfs_debug.c, zfs_racct.c

### Compile (lib trees)

lib/libspl/*.c (done), lib/libnvpair/*.c + module/nvpair/*.c
(done), lib/libavl/avl.c, lib/libunicode/*.c, lib/libzutil/*.c

### Stub out entirely (src/shim/disabled_features.c)

All `dsl_crypto_*`, `zio_crypt_*`, `spa_keystore_*`, `hkdf_*`,
`ddt_*`, `brt_*`, `dmu_send_*`, `dmu_recv_*`, `dmu_redact_*`,
`dmu_diff_*`, `zcp_*`, `vdev_raidz_*`, `vdev_draid_*`,
`zil_crypt_*` — every function compiled-but-dead. Each stub
returns `SET_ERROR(ENOTSUP)` and, for predicate functions,
returns false.

## Entry point

The `libzpool` userland build in `lib/libzpool/` compiles `module/zfs/*.c`
unmodified against a kernel-emulation shim (`lib/libspl/` + `lib/libzpool/
kernel.c`). `ztest` and `zdb` already drive the DMU/SPA stack in userland
through this path without touching `/dev/zfs` or any ioctl. That is the
slice we compile to wasm.

## Exposed C API

A thin C wrapper (`src/zfswasm.c`) sits on top of `libzpool` and exposes
exactly what the demo needs. JS sees these through `wasm-bindgen`-style
glue.

```c
int  zfswasm_init(void);                                /* once per worker */
int  zfswasm_pool_create(const char *pool, const char *backing_file,
                         uint64_t size_bytes);
int  zfswasm_pool_import(const char *pool, const char *backing_file);
int  zfswasm_pool_export(const char *pool);

int  zfswasm_ds_create(const char *fullname);            /* "pool/ds"        */
int  zfswasm_ds_destroy(const char *fullname);
int  zfswasm_ds_list(const char *pool, char **json_out);

int  zfswasm_snap(const char *ds, const char *snapname); /* "pool/ds@snap"   */
int  zfswasm_snap_destroy(const char *full);
int  zfswasm_clone(const char *snap, const char *newds);

int  zfswasm_file_write(const char *ds, const char *path,
                        const void *buf, size_t len);
int  zfswasm_file_read (const char *ds, const char *path,
                        void *buf, size_t cap, size_t *out_len);
int  zfswasm_file_list (const char *ds, const char *path, char **json_out);
int  zfswasm_file_unlink(const char *ds, const char *path);
```

Files live inside the DMU object set as dnodes of type `DMU_OT_PLAIN_FILE_
CONTENTS`. We do not mount a vfs; we address objects through small
wrappers over `dmu_object_alloc`, `dmu_write`, `dmu_read`, `zap_add` /
`zap_lookup` for the directory ZAPs. That is enough to mirror a WP
docroot and a MySQL datadir.

## Backing file host bridge

The wasm build reads and writes its backing pool file through
`zfs_file_pread` / `zfs_file_pwrite`. In the wasm target these go through
the host: on Node, plain `fs.read`/`fs.write`; in the browser, an OPFS
`FileSystemSyncAccessHandle` in a dedicated worker. The browser path is
a follow-up; Node is the first milestone.

## Build milestones

1. libnvpair builds under emcc (smoke test — ZFS headers compile at all).
2. libspl builds, with signal/backtrace stubs. Emscripten pthreads mode.
3. libzpool kernel shim builds (lib/libzpool/kernel.c + zfs_file_os.c).
4. module/zfs core builds (excluded files listed above).
5. libzfs-core-equivalent wrapper `zfswasm.c` links with the archive.
6. Node host opens a pool on a regular file, creates a dataset, writes a
   file, snapshots, clones, reads from the clone. This is "Hello, ZFS".
7. Browser host via OPFS.
8. GitHub Pages demo swapped to the real bundle.

## Known risks (tracked, not hidden)

- Emscripten pthreads cannot `pthread_cond_wait` on the main browser
  thread. All pool work must run in a worker via `-sPROXY_TO_PTHREAD`.
- 150k+ LOC of CDDL C going through emcc for the first time. Expect
  failures around `VERIFY` / `ASSERT`, alignment, atomics, and
  endianness assumptions. Each will be patched in `patches/`.
- OpenZFS's autoconf is Linux-kernel-aware. We bypass `./configure` and
  drive the build with a hand-rolled Makefile under the emsdk image.

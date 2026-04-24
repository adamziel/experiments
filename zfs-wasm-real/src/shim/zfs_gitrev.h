/*
 * Stand-in for the autoconf-generated zfs_gitrev.h.
 *
 * OpenZFS's configure writes this to upstream/zfs/include/zfs_gitrev.h
 * with the short git revision of the source tree. We never run ./configure
 * in CI (we drive the build from a hand-rolled Makefile), so spa_history.c's
 * `#include "zfs_gitrev.h"` would fail. A fixed label is fine — the demo
 * logs it once in spa_history and we don't care about the exact hash.
 */
#ifndef _ZFS_GITREV_H
#define _ZFS_GITREV_H
#define ZFS_META_GITREV "zfs-2.2.6-wasm"
#endif

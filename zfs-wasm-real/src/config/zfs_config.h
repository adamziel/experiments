/*
 * Hand-rolled zfs_config.h for the wasm userland build.
 *
 * Normally autoconf generates this by running ./configure. We bypass
 * configure because its probes are Linux-kernel-aware and produce
 * defines that do not describe a wasm32 target. Instead we declare
 * only the features we want the OpenZFS userland to assume.
 *
 * Keep this file as small as possible. Add a define only when an
 * #ifdef we need forces us to.
 */
#ifndef _ZFS_CONFIG_H
#define _ZFS_CONFIG_H

/* Package identity — some source files use these in log strings. */
#define PACKAGE_NAME    "zfs"
#define PACKAGE_VERSION "2.2.6-wasm"
#define ZFS_META_VERSION "2.2.6-wasm"
#define ZFS_META_RELEASE "wasm"

/* Build mode: this is the userland / libzpool variant, never the
 * kernel module. Keep _KERNEL undefined. */
#undef _KERNEL

/* Threading is available (emscripten pthreads). */
#define HAVE_PTHREAD_MUTEX_ROBUST 1

/* We have <sys/uio.h> via libspl, fletcher4 scalar only. */
#define HAVE_FLETCHER_4_SCALAR 1

/* dgettext(TEXT_DOMAIN, ...) appears in several error-print paths.
 * Autoconf normally sets this; provide a stable value. */
#ifndef TEXT_DOMAIN
#define TEXT_DOMAIN     "zfs-wasm"
#endif


#endif /* _ZFS_CONFIG_H */

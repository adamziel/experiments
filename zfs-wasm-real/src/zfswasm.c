/*
 * zfswasm: thin C wrapper around libzpool exposing the pool/dataset/file
 * API needed by the WordPress branching demo.
 *
 * Data layout
 * -----------
 * Datasets are created with DMU_OST_OTHER so we skip the ZPL/znode
 * layer. During ds_create we claim object id 1 (ZFSWASM_ROOT_ZAP_OBJ)
 * as a ZAP of DMU_OT_DIRECTORY_CONTENTS. That ZAP maps
 * path -> (fileobj, length) stored as two uint64_t values.
 *
 * Threading
 * ---------
 * Built with -sPROXY_TO_PTHREAD=1. main() runs on a dedicated "ZFS
 * worker" pthread and pumps a proxying queue. Every exported
 * zfswasm_*_begin entry point is a *non-blocking* enqueue: it calls
 * emscripten_proxy_async to schedule the real implementation on the
 * ZFS worker and returns immediately. The JS caller (which may be
 * Emscripten's main runtime thread — where pthread condvar waits are
 * forbidden) then polls zfswasm_poll() with a setTimeout loop until
 * the task completes. The ZFS worker is a pthread, so it may block on
 * cv_wait inside spa_activate / txg_sync / taskq drains without
 * deadlocking the main runtime thread (which stays free to service
 * proxied calls from taskq pthreads).
 *
 * This avoids the "unwind" failure seen in earlier iterations where
 * emscripten_proxy_sync from the main runtime thread tripped the
 * main-thread-blocking check.
 */

#include <stdint.h>
#include <stddef.h>
#include <stdio.h>
#include <errno.h>
#include <string.h>
#include <time.h>
#include <stdlib.h>
#include <emscripten.h>
#include <emscripten/proxying.h>
#include <emscripten/threading.h>
#include <pthread.h>

#include <sys/zfs_context.h>
#include <sys/spa.h>
#include <sys/dmu.h>
#include <sys/dmu_objset.h>
#include <sys/dmu_tx.h>
#include <sys/dsl_dataset.h>
#include <sys/dsl_destroy.h>
#include <sys/dsl_dir.h>
#include <sys/dsl_pool.h>
#include <sys/zap.h>
#include <sys/txg.h>

#define ZFSWASM_ROOT_ZAP_OBJ 1

static em_proxying_queue *g_zfs_q;
static pthread_t g_zfs_tid;
static volatile int g_zfs_ready;

/* single-slot task state — smoke test is single-threaded caller */
static volatile int g_task_in_flight;
static volatile int g_task_done;
static volatile int g_task_rc;

int
main(int argc, char **argv)
{
    (void) argc; (void) argv;
    g_zfs_tid = pthread_self();
    g_zfs_q = em_proxying_queue_create();
    __atomic_store_n(&g_zfs_ready, 1, __ATOMIC_SEQ_CST);
    for (;;) {
        emscripten_proxy_execute_queue(g_zfs_q);
        /*
         * emscripten_thread_sleep blocks the pthread briefly so the
         * event loop can schedule other work — cheap and legal
         * because we're on a pthread, not the main browser thread.
         */
        emscripten_thread_sleep(5);
    }
    return (0);
}

/* -------- real implementations (run on the ZFS worker pthread) -------- */

static int
impl_init(void)
{
    kernel_init(SPA_MODE_READ | SPA_MODE_WRITE);
    return (0);
}

static int
impl_fini(void)
{
    kernel_fini();
    return (0);
}

static int
impl_pool_create(const char *pool, const char *backing_file)
{
    nvlist_t *nvroot, *child;
    int err;

    child = fnvlist_alloc();
    fnvlist_add_string(child, ZPOOL_CONFIG_TYPE, VDEV_TYPE_FILE);
    fnvlist_add_string(child, ZPOOL_CONFIG_PATH, backing_file);
    fnvlist_add_uint64(child, ZPOOL_CONFIG_IS_LOG, 0);

    nvroot = fnvlist_alloc();
    fnvlist_add_string(nvroot, ZPOOL_CONFIG_TYPE, VDEV_TYPE_ROOT);
    fnvlist_add_nvlist_array(nvroot, ZPOOL_CONFIG_CHILDREN,
        (const nvlist_t **)&child, 1);

    err = spa_create(pool, nvroot, NULL, NULL, NULL);

    fnvlist_free(child);
    fnvlist_free(nvroot);
    return (err);
}

static int
impl_pool_export(const char *pool)
{
    return (spa_export(pool, NULL, B_TRUE, B_FALSE));
}

static void
impl_ds_create_cb(objset_t *os, void *arg, cred_t *cr, dmu_tx_t *tx)
{
    (void) arg; (void) cr;
    (void) zap_create_claim(os, ZFSWASM_ROOT_ZAP_OBJ,
        DMU_OT_DIRECTORY_CONTENTS, DMU_OT_NONE, 0, tx);
}

static int
impl_ds_create(const char *fullname)
{
    return (dmu_objset_create(fullname, DMU_OST_OTHER, 0, NULL,
        impl_ds_create_cb, NULL));
}

static int
impl_ds_destroy(const char *fullname)
{
    return (dsl_destroy_head(fullname));
}

static int
impl_snap(const char *ds, const char *snapname)
{
    char full[ZFS_MAX_DATASET_NAME_LEN];
    nvlist_t *snaps = fnvlist_alloc();
    nvlist_t *errors = NULL;
    int err;

    (void) snprintf(full, sizeof (full), "%s@%s", ds, snapname);
    fnvlist_add_boolean(snaps, full);
    err = dsl_dataset_snapshot(snaps, NULL, &errors);
    fnvlist_free(snaps);
    if (errors != NULL)
        fnvlist_free(errors);
    return (err);
}

static int
impl_snap_destroy(const char *full)
{
    return (dsl_destroy_snapshot(full, B_FALSE));
}

static int
impl_clone(const char *snap, const char *newds)
{
    return (dmu_objset_clone(newds, snap));
}

/*
 * rollback: discard everything in `ds` after snapshot `snapname` so
 * the dataset's content matches the snapshot. The snapshot is
 * referenced by short name; we expand it to fullname inside.
 */
static int
impl_rollback(const char *ds, const char *snapname)
{
    char tosnap[ZFS_MAX_DATASET_NAME_LEN];
    nvlist_t *result = NULL;
    int err;

    if (snprintf(tosnap, sizeof (tosnap), "%s@%s", ds, snapname)
        >= (int) sizeof (tosnap))
        return (ENAMETOOLONG);

    /* owner=NULL: no userland holder; result captures snaps that
     * had to be destroyed (newer than tosnap). We discard it. */
    err = dsl_dataset_rollback(ds, tosnap, NULL, result);
    if (result != NULL)
        nvlist_free(result);
    return (err);
}

/*
 * promote: turn a clone into the parent of the dataset it was
 * cloned from. After promote(), the original dataset depends on
 * the clone instead of the other way around — the user can then
 * destroy the original to "merge" the clone's state in place.
 */
static int
impl_promote(const char *clone)
{
    char conflict[ZFS_MAX_DATASET_NAME_LEN] = "";
    return (dsl_dataset_promote(clone, conflict));
}

/*
 * file_write: create-or-update a logical file inside `ds`. On first
 * write we allocate a fresh DMU object; on subsequent writes we
 * reuse + truncate. After commit, txg_wait_synced so snapshots taken
 * later observe the write.
 */
static int
impl_file_write(const char *ds, const char *path,
    const void *buf, size_t len)
{
    objset_t *os;
    dmu_tx_t *tx;
    uint64_t entry[2] = {0, 0};
    uint64_t fileobj;
    int err;

    err = dmu_objset_own(ds, DMU_OST_OTHER, B_FALSE, B_FALSE, FTAG, &os);
    if (err != 0)
        return (err);

    err = zap_lookup(os, ZFSWASM_ROOT_ZAP_OBJ, path, 8, 2, entry);
    if (err != 0 && err != ENOENT)
        goto out;

    tx = dmu_tx_create(os);
    if (entry[0] == 0) {
        dmu_tx_hold_zap(tx, ZFSWASM_ROOT_ZAP_OBJ, B_TRUE, path);
        dmu_tx_hold_write(tx, DMU_NEW_OBJECT, 0, len);
    } else {
        dmu_tx_hold_zap(tx, ZFSWASM_ROOT_ZAP_OBJ, B_TRUE, path);
        dmu_tx_hold_write(tx, entry[0], 0, len);
        dmu_tx_hold_free(tx, entry[0], 0, DMU_OBJECT_END);
    }
    err = dmu_tx_assign(tx, TXG_WAIT);
    if (err != 0) {
        dmu_tx_abort(tx);
        goto out;
    }

    if (entry[0] == 0) {
        fileobj = dmu_object_alloc(os, DMU_OT_PLAIN_FILE_CONTENTS, 0,
            DMU_OT_NONE, 0, tx);
    } else {
        fileobj = entry[0];
        (void) dmu_free_range(os, fileobj, 0, DMU_OBJECT_END, tx);
    }
    if (len > 0)
        dmu_write(os, fileobj, 0, len, buf, tx);

    entry[0] = fileobj;
    entry[1] = (uint64_t)len;
    err = zap_update(os, ZFSWASM_ROOT_ZAP_OBJ, path, 8, 2, entry, tx);
    dmu_tx_commit(tx);
    if (err != 0)
        goto out;

    txg_wait_synced(dmu_objset_pool(os), 0);
out:
    dmu_objset_disown(os, B_FALSE, FTAG);
    return (err);
}

/*
 * file_read: look up (fileobj, len) in the root ZAP and dmu_read it.
 * Works for snapshots and clones. *out_len = bytes copied on success.
 */
static int
impl_file_read(const char *ds, const char *path,
    void *buf, size_t cap, size_t *out_len)
{
    objset_t *os;
    uint64_t entry[2];
    size_t to_copy;
    int err;

    err = dmu_objset_hold(ds, FTAG, &os);
    if (err != 0)
        return (err);

    err = zap_lookup(os, ZFSWASM_ROOT_ZAP_OBJ, path, 8, 2, entry);
    if (err != 0)
        goto out;

    to_copy = entry[1];
    if (to_copy > cap)
        to_copy = cap;
    if (to_copy > 0) {
        err = dmu_read(os, entry[0], 0, to_copy, buf, DMU_READ_PREFETCH);
        if (err != 0)
            goto out;
    }
    if (out_len != NULL)
        *out_len = to_copy;
out:
    dmu_objset_rele(os, FTAG);
    return (err);
}

/* ---------------- async dispatch plumbing ----------------------------- */

/*
 * Arg structs hold owned copies of any string arg. cwrap('string')
 * allocates on the JS stack and frees it when the wrapped call
 * returns; our _begin calls return immediately, so by the time the
 * proxied impl runs on the ZFS worker those pointers would be stale.
 * We strdup on enqueue and free after the callback.
 */
struct args_init  { int dummy; };
struct args_fini  { int dummy; };
struct args_s1    { char *a; };
struct args_s2    { char *a; char *b; };
struct args_write { char *ds; char *path;
    const void *buf; size_t len; };
struct args_read  { char *ds; char *path;
    void *buf; size_t cap; size_t *out_len; };

static struct args_init  a_init;
static struct args_fini  a_fini;
static struct args_s1    a_s1;
static struct args_s2    a_s2;
static struct args_write a_write;
static struct args_read  a_read;

#define DONE() __atomic_store_n(&g_task_done, 1, __ATOMIC_SEQ_CST)

static void cb_init(void *p) { (void)p; g_task_rc = impl_init(); DONE(); }
static void cb_fini(void *p) { (void)p; g_task_rc = impl_fini(); DONE(); }
static void cb_pool_create(void *p) {
    struct args_s2 *a = p;
    g_task_rc = impl_pool_create(a->a, a->b);
    free(a->a); free(a->b);
    DONE();
}
static void cb_pool_export(void *p) {
    struct args_s1 *a = p;
    g_task_rc = impl_pool_export(a->a);
    free(a->a);
    DONE();
}
static void cb_ds_create(void *p) {
    struct args_s1 *a = p;
    g_task_rc = impl_ds_create(a->a);
    free(a->a);
    DONE();
}
static void cb_ds_destroy(void *p) {
    struct args_s1 *a = p;
    g_task_rc = impl_ds_destroy(a->a);
    free(a->a);
    DONE();
}
static void cb_snap(void *p) {
    struct args_s2 *a = p;
    g_task_rc = impl_snap(a->a, a->b);
    free(a->a); free(a->b);
    DONE();
}
static void cb_snap_destroy(void *p) {
    struct args_s1 *a = p;
    g_task_rc = impl_snap_destroy(a->a);
    free(a->a);
    DONE();
}
static void cb_clone(void *p) {
    struct args_s2 *a = p;
    g_task_rc = impl_clone(a->a, a->b);
    free(a->a); free(a->b);
    DONE();
}
static void cb_rollback(void *p) {
    struct args_s2 *a = p;
    g_task_rc = impl_rollback(a->a, a->b);
    free(a->a); free(a->b);
    DONE();
}
static void cb_promote(void *p) {
    struct args_s1 *a = p;
    g_task_rc = impl_promote(a->a);
    free(a->a);
    DONE();
}
static void cb_file_write(void *p) {
    struct args_write *a = p;
    g_task_rc = impl_file_write(a->ds, a->path, a->buf, a->len);
    free(a->ds); free(a->path);
    DONE();
}
static void cb_file_read(void *p) {
    struct args_read *a = p;
    g_task_rc = impl_file_read(a->ds, a->path, a->buf, a->cap, a->out_len);
    free(a->ds); free(a->path);
    DONE();
}

static int
enqueue(void (*cb)(void *), void *arg)
{
    /* Busy-wait until the ZFS worker has initialized the queue. This
     * is a pthread on the caller's side only at module startup; the
     * flag flips within microseconds of module load. */
    while (!__atomic_load_n(&g_zfs_ready, __ATOMIC_SEQ_CST)) {
        /* nothing — caller is on JS main runtime thread, cannot
         * nanosleep there reliably; the ready flag flips so fast
         * this loop is effectively a no-op in practice. */
    }
    if (__atomic_exchange_n(&g_task_in_flight, 1, __ATOMIC_SEQ_CST) != 0)
        return (EBUSY);
    __atomic_store_n(&g_task_done, 0, __ATOMIC_SEQ_CST);
    g_task_rc = 0;
    if (!emscripten_proxy_async(g_zfs_q, g_zfs_tid, cb, arg)) {
        __atomic_store_n(&g_task_in_flight, 0, __ATOMIC_SEQ_CST);
        return (EIO);
    }
    return (0);
}

/* ---------------- exported begin/poll API ---------------------------- */

EMSCRIPTEN_KEEPALIVE int zfswasm_poll(void) {
    return __atomic_load_n(&g_task_done, __ATOMIC_SEQ_CST) ? 1 : 0;
}

EMSCRIPTEN_KEEPALIVE int zfswasm_result(void) {
    int rc = g_task_rc;
    __atomic_store_n(&g_task_in_flight, 0, __ATOMIC_SEQ_CST);
    return rc;
}

EMSCRIPTEN_KEEPALIVE int zfswasm_init_begin(void) {
    return enqueue(cb_init, &a_init);
}
EMSCRIPTEN_KEEPALIVE int zfswasm_fini_begin(void) {
    return enqueue(cb_fini, &a_fini);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_pool_create_begin(const char *pool, const char *backing) {
    a_s2.a = strdup(pool); a_s2.b = strdup(backing);
    return enqueue(cb_pool_create, &a_s2);
}
EMSCRIPTEN_KEEPALIVE int zfswasm_pool_export_begin(const char *pool) {
    a_s1.a = strdup(pool);
    return enqueue(cb_pool_export, &a_s1);
}
EMSCRIPTEN_KEEPALIVE int zfswasm_ds_create_begin(const char *name) {
    a_s1.a = strdup(name);
    return enqueue(cb_ds_create, &a_s1);
}
EMSCRIPTEN_KEEPALIVE int zfswasm_ds_destroy_begin(const char *name) {
    a_s1.a = strdup(name);
    return enqueue(cb_ds_destroy, &a_s1);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_snap_begin(const char *ds, const char *snap) {
    a_s2.a = strdup(ds); a_s2.b = strdup(snap);
    return enqueue(cb_snap, &a_s2);
}
EMSCRIPTEN_KEEPALIVE int zfswasm_snap_destroy_begin(const char *full) {
    a_s1.a = strdup(full);
    return enqueue(cb_snap_destroy, &a_s1);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_clone_begin(const char *snap, const char *newds) {
    a_s2.a = strdup(snap); a_s2.b = strdup(newds);
    return enqueue(cb_clone, &a_s2);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_rollback_begin(const char *ds, const char *snapname) {
    a_s2.a = strdup(ds); a_s2.b = strdup(snapname);
    return enqueue(cb_rollback, &a_s2);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_promote_begin(const char *clone) {
    a_s1.a = strdup(clone);
    return enqueue(cb_promote, &a_s1);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_file_write_begin(const char *ds, const char *path,
    const void *buf, size_t len) {
    a_write.ds = strdup(ds); a_write.path = strdup(path);
    a_write.buf = buf; a_write.len = len;
    return enqueue(cb_file_write, &a_write);
}
EMSCRIPTEN_KEEPALIVE int
zfswasm_file_read_begin(const char *ds, const char *path,
    void *buf, size_t cap, size_t *out_len) {
    a_read.ds = strdup(ds); a_read.path = strdup(path);
    a_read.buf = buf; a_read.cap = cap; a_read.out_len = out_len;
    return enqueue(cb_file_read, &a_read);
}

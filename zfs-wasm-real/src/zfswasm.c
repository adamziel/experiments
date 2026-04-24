/*
 * zfswasm: thin C wrapper around libzpool exposing the pool/dataset/file
 * API needed by the WordPress branching demo.
 *
 * Data layout note
 * ----------------
 * Our datasets are created with DMU_OST_OTHER (not DMU_OST_ZFS) so we
 * avoid pulling in the ZPL/znode layer. Instead, during ds_create we
 * claim object id 1 (ZFSWASM_ROOT_ZAP_OBJ) as a ZAP of type
 * DMU_OT_DIRECTORY_CONTENTS. That ZAP maps path -> (fileobj, length)
 * stored as two uint64_t values (integer size 8, int count 2).
 *
 * file_write creates the fileobj on first write via dmu_object_alloc,
 * writes data with dmu_write, and updates the ZAP entry with the new
 * (obj, len) pair. file_read looks up the ZAP entry and dmu_read's
 * `len` bytes into the caller's buffer.
 */

#include <stdint.h>
#include <stddef.h>
#include <stdio.h>
#include <errno.h>
#include <string.h>
#include <emscripten.h>

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

EMSCRIPTEN_KEEPALIVE
int
zfswasm_init(void)
{
    kernel_init(SPA_MODE_READ | SPA_MODE_WRITE);
    return (0);
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_fini(void)
{
    kernel_fini();
    return (0);
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_pool_create(const char *pool, const char *backing_file)
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

EMSCRIPTEN_KEEPALIVE
int
zfswasm_pool_export(const char *pool)
{
    return (spa_export(pool, NULL, B_TRUE, B_FALSE));
}

/*
 * Create-time callback: claim object id 1 as our root directory ZAP.
 * Runs inside the objset-creation txg, so it's guaranteed to be the
 * first user object allocated and the id is stable across snap/clone.
 */
static void
zfswasm_ds_create_cb(objset_t *os, void *arg, cred_t *cr, dmu_tx_t *tx)
{
    (void) arg; (void) cr;
    (void) zap_create_claim(os, ZFSWASM_ROOT_ZAP_OBJ,
        DMU_OT_DIRECTORY_CONTENTS, DMU_OT_NONE, 0, tx);
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_ds_create(const char *fullname)
{
    return (dmu_objset_create(fullname, DMU_OST_OTHER, 0, NULL,
        zfswasm_ds_create_cb, NULL));
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_ds_destroy(const char *fullname)
{
    return (dsl_destroy_head(fullname));
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_snap(const char *ds, const char *snapname)
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

EMSCRIPTEN_KEEPALIVE
int
zfswasm_snap_destroy(const char *full)
{
    return (dsl_destroy_snapshot(full, B_FALSE));
}

EMSCRIPTEN_KEEPALIVE
int
zfswasm_clone(const char *snap, const char *newds)
{
    return (dmu_objset_clone(newds, snap));
}

/*
 * file_write: create-or-update a logical file named `path` inside `ds`
 * with `len` bytes from `buf`. On first write for a given path we
 * allocate a new DMU object; on subsequent writes we reuse it and
 * truncate+overwrite.
 *
 * After the writing tx commits we txg_wait_synced so that any snapshot
 * taken afterwards observes the written data.
 */
EMSCRIPTEN_KEEPALIVE
int
zfswasm_file_write(const char *ds, const char *path,
    const void *buf, size_t len)
{
    objset_t *os;
    dmu_tx_t *tx;
    uint64_t entry[2] = {0, 0};
    uint64_t fileobj;
    int err;

    err = dmu_objset_own(ds, DMU_OST_OTHER, B_FALSE, B_FALSE,
        FTAG, &os);
    if (err != 0)
        return (err);

    /*
     * Look up existing (fileobj, len) pair, if any. zap_lookup with
     * integer_size=8 and num_integers=2 fills both slots atomically.
     */
    err = zap_lookup(os, ZFSWASM_ROOT_ZAP_OBJ, path, 8, 2, entry);
    if (err != 0 && err != ENOENT)
        goto out;

    tx = dmu_tx_create(os);
    if (entry[0] == 0) {
        /* First write: allocate a fresh object and claim it in the ZAP. */
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
        /* Truncate any old data past len. */
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

    /* Make sure subsequent snapshots see this write. */
    txg_wait_synced(dmu_objset_pool(os), 0);
out:
    dmu_objset_disown(os, B_FALSE, FTAG);
    return (err);
}

/*
 * file_read: look up (fileobj, len) in the root ZAP and dmu_read
 * the data into `buf`. Accepts snapshots and clones (read-only or
 * not). On success *out_len is set to the number of bytes copied.
 */
EMSCRIPTEN_KEEPALIVE
int
zfswasm_file_read(const char *ds, const char *path,
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
        err = dmu_read(os, entry[0], 0, to_copy, buf,
            DMU_READ_PREFETCH);
        if (err != 0)
            goto out;
    }
    if (out_len != NULL)
        *out_len = to_copy;
out:
    dmu_objset_rele(os, FTAG);
    return (err);
}

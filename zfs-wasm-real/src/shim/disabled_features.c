/*
 * Stubs for every ZFS function that lives in a source file we do NOT
 * compile. The module/zfs subset we build still *references* these
 * symbols (e.g. dsl_dataset.c calls dsl_crypto_key_unload; dmu.c
 * references ddt_*). The call sites are never exercised because the
 * demo never enables encryption / dedup / send / raidz, but the linker
 * still needs a definition.
 *
 * Each stub either returns SET_ERROR(ENOTSUP) or does nothing. A real
 * runtime call here is a bug; the WordPress branching demo touches
 * none of this.
 */

#include <sys/types.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>

#include <sys/isa_defs.h>
#include <sys/sysmacros.h>

#define STUB_ENOTSUP do { return (ENOTSUP); } while (0)

/* dsl_crypt / zio_crypt / keystore / hkdf */
int dsl_crypto_key_unload(const char *c) { (void)c; STUB_ENOTSUP; }
int dsl_crypto_key_load(const char *a, void *b) { (void)a;(void)b; STUB_ENOTSUP; }
int dsl_crypto_key_rewrap(const char *a, void *b, void *c) { (void)a;(void)b;(void)c; STUB_ENOTSUP; }
int dsl_crypto_populate_key_nvlist(void *a, uint64_t b, void *c) { (void)a;(void)b;(void)c; STUB_ENOTSUP; }
int dsl_crypto_recv_key(const char *a, uint64_t b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
void spa_keystore_init(void *k) { (void)k; }
void spa_keystore_fini(void *k) { (void)k; }
int spa_keystore_load_wkey(const char *a, void *b, boolean_t c) { (void)a;(void)b;(void)c; STUB_ENOTSUP; }
int spa_keystore_unload_wkey(const char *a) { (void)a; STUB_ENOTSUP; }
int spa_keystore_lookup_key(void *a, uint64_t b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
int spa_keystore_create_mapping(void *a, void *b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
int spa_keystore_remove_mapping(void *a, uint64_t b, void *c) { (void)a;(void)b;(void)c; STUB_ENOTSUP; }
int spa_keystore_wkey_hold_dd(void *a, void *b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
int spa_keystore_change_key(const char *a, void *b) { (void)a;(void)b; STUB_ENOTSUP; }
int spa_keystore_rewrap_check(void *tx) { (void)tx; return 0; }
void spa_keystore_dsl_key_rele(void *a, void *b, void *c) { (void)a;(void)b;(void)c; }
int spa_keystore_dsl_key_hold_dd(void *a, void *b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
int spa_keystore_dsl_key_hold_key(void *a, uint64_t b, void *c, void *d) { (void)a;(void)b;(void)c;(void)d; STUB_ENOTSUP; }
int dmu_objset_create_crypt_check(void *a, void *b, boolean_t *c) { (void)a;(void)b;(void)c; return 0; }
int dmu_objset_clone_crypt_check(void *a, void *b) { (void)a;(void)b; return 0; }
int dsl_crypto_can_set_keylocation(const char *a, const char *b) { (void)a;(void)b; return 0; }

int hkdf_sha512(const uint8_t *a, int b, const uint8_t *c, int d,
    const uint8_t *e, int f, uint8_t *g, int h)
{ (void)a;(void)b;(void)c;(void)d;(void)e;(void)f;(void)g;(void)h; STUB_ENOTSUP; }

/* ddt / brt */
void ddt_create(void *spa) { (void)spa; }
void ddt_unload(void *spa) { (void)spa; }
int ddt_load(void *spa) { (void)spa; return 0; }
int ddt_object_count(void *ddt, int type, int class, uint64_t *c) { (void)ddt;(void)type;(void)class;(void)c; *c=0; return 0; }
void ddt_sync(void *spa, uint64_t txg) { (void)spa;(void)txg; }
void ddt_destroy(void *ddt) { (void)ddt; }
int ddt_entry_compare(const void *a, const void *b) { (void)a;(void)b; return 0; }
boolean_t ddt_class_contains(void *spa, int cls, void *bp) { (void)spa;(void)cls;(void)bp; return B_FALSE; }
void ddt_prefetch(void *spa, void *bp) { (void)spa;(void)bp; }
void ddt_prefetch_all(void *spa) { (void)spa; }
int ddt_zap_count(void *ddt, uint64_t *c) { (void)ddt; *c=0; return 0; }
uint64_t ddt_get_dedup_dspace(void *spa) { (void)spa; return 0; }
uint64_t ddt_get_ddt_dsize(void *spa) { (void)spa; return 0; }
uint64_t ddt_get_dedup_histogram_size(void *spa) { (void)spa; return 0; }
void ddt_get_dedup_histogram(void *spa, void *ddh) { (void)spa;(void)ddh; }
void ddt_get_dedup_stats(void *spa, void *dds) { (void)spa;(void)dds; memset(dds, 0, 1); }
uint64_t ddt_get_dedup_object_count(void *spa) { (void)spa; return 0; }
uint64_t ddt_get_dedup_dspace_saving(void *spa) { (void)spa; return 0; }
uint64_t ddt_get_pool_dedup_ratio(void *spa) { (void)spa; return 100; }

void brt_create(void *spa) { (void)spa; }
void brt_destroy(void *spa) { (void)spa; }
int brt_load(void *spa) { (void)spa; return 0; }
void brt_unload(void *spa) { (void)spa; }
void brt_sync(void *spa, uint64_t txg) { (void)spa;(void)txg; }
uint64_t brt_get_used(void *spa) { (void)spa; return 0; }
uint64_t brt_get_saved(void *spa) { (void)spa; return 0; }
uint64_t brt_get_ratio(void *spa) { (void)spa; return 100; }
boolean_t brt_maybe_exists(void *spa, void *bp) { (void)spa;(void)bp; return B_FALSE; }
void brt_pending_add(void *spa, void *bp, void *tx) { (void)spa;(void)bp;(void)tx; }
void brt_pending_remove(void *spa, void *bp, void *tx) { (void)spa;(void)bp;(void)tx; }
void brt_pending_apply(void *spa, uint64_t txg) { (void)spa;(void)txg; }
void brt_vdev_create(void *spa, void *vd, void *tx) { (void)spa;(void)vd;(void)tx; }
void brt_vdev_destroy(void *spa, void *vd, void *tx) { (void)spa;(void)vd;(void)tx; }
uint64_t brt_entry_get_refcount(void *spa, void *bp) { (void)spa;(void)bp; return 0; }
int brt_entry_decref(void *spa, void *bp) { (void)spa;(void)bp; return 0; }

/* dmu_send / dmu_recv / dmu_redact / dmu_diff */
int dmu_send(const char *a, const char *b, boolean_t c, boolean_t d, boolean_t e, boolean_t f, boolean_t g, boolean_t h, boolean_t i, boolean_t j, boolean_t k, int l, uint64_t m, uint64_t n, void *o, void *p, void *q) { STUB_ENOTSUP; }
int dmu_send_obj(const char *a, uint64_t b, boolean_t c, boolean_t d, boolean_t e, boolean_t f, boolean_t g, int h, uint64_t i, uint64_t j, void *k) { STUB_ENOTSUP; }
int dmu_send_estimate_fast(void *a, void *b, void *c, boolean_t d, uint64_t e, uint64_t *f) { if (f) *f = 0; return 0; }
int dmu_recv_begin(char *a, char *b, void *c, boolean_t d, boolean_t e, void *f, void *g, void *h, char *i, void *j) { STUB_ENOTSUP; }
int dmu_recv_stream(void *a, uint64_t *b, uint64_t *c, int d) { STUB_ENOTSUP; }
int dmu_recv_end(void *a, void *b) { STUB_ENOTSUP; }
boolean_t dmu_objset_is_receiving(void *os) { (void)os; return B_FALSE; }
int dmu_diff(const char *a, const char *b, int c, void *d) { STUB_ENOTSUP; }
int dmu_redact_snap(const char *a, void *b, const char *c) { STUB_ENOTSUP; }

/* zcp (lua channel programs) */
int zcp_eval(const char *a, const char *b, boolean_t c, uint64_t d, uint64_t e, void *f, void *g) { STUB_ENOTSUP; }

/* bookmarks */
int dsl_bookmark_create(void *a, void *b) { STUB_ENOTSUP; }
int dsl_bookmark_create_redacted(const char *a, const char *b, uint64_t c, uint64_t *d, void *e, void **f) { STUB_ENOTSUP; }
int dsl_bookmark_lookup(void *a, const char *b, void *c, void *d) { STUB_ENOTSUP; }
int dsl_bookmark_lookup_impl(void *a, const char *b, void *c) { STUB_ENOTSUP; }
int dsl_bookmark_destroy(void *a, void *b) { STUB_ENOTSUP; }
int dsl_get_bookmarks(const char *a, void *b, void *c) { STUB_ENOTSUP; }
int dsl_get_bookmarks_impl(void *a, void *b, void *c) { STUB_ENOTSUP; }
int dsl_get_bookmark_props(const char *a, const char *b, void *c) { STUB_ENOTSUP; }
int dsl_redaction_list_hold_obj(void *a, uint64_t b, void *c, void **d) { STUB_ENOTSUP; }
void dsl_redaction_list_rele(void *rl, void *tag) { (void)rl;(void)tag; }
void dsl_redaction_list_long_hold(void *a, void *b, void *c) { (void)a;(void)b;(void)c; }
void dsl_redaction_list_long_rele(void *a, void *b) { (void)a;(void)b; }
int dsl_redaction_list_hold_obj_named(void *a, const char *b, void *c, void **d) { STUB_ENOTSUP; }
int dsl_bookmark_ds_destroyed(void *ds, void *tx) { (void)ds;(void)tx; return 0; }
void dsl_bookmark_next_changed(void *a, void *b, void *c) { (void)a;(void)b;(void)c; }
uint64_t dsl_bookmark_latest_txg(void *ds) { (void)ds; return 0; }

/* vdev_raidz / vdev_draid */
int vdev_raidz_impl_set(const char *s) { (void)s; return ENOTSUP; }
void vdev_raidz_math_init(void) {}
void vdev_raidz_math_fini(void) {}
int vdev_draid_lookup_map(uint64_t a, void **b) { STUB_ENOTSUP; }
/* No draid spares in our topology — success with ndraid=0. */
int vdev_draid_spare_create(void *a, void *b, uint64_t *c, uint64_t d)
{ (void)a;(void)b;(void)d; if (c) *c = 0; return (0); }
int vdev_draid_spare_par_open(void *a, uint64_t *b, uint64_t *c) { STUB_ENOTSUP; }
const char *vdev_draid_get_name(void *vd) { (void)vd; return "<none>"; }
boolean_t vdev_draid_readable(void *vd, uint64_t b) { (void)vd;(void)b; return B_FALSE; }
boolean_t vdev_draid_missing(void *vd, uint64_t a, uint64_t b, uint64_t c) { (void)vd;(void)a;(void)b;(void)c; return B_FALSE; }
boolean_t vdev_draid_is_remove_wanted(void *vd) { (void)vd; return B_FALSE; }

/* zil crypto pieces */
int zil_crypt_init(void) { return 0; }
void zil_crypt_fini(void) {}

/*
 * Checksum providers — signatures must match zio_checksum_t exactly:
 *   void fn(abd_t *abd, uint64_t size, const void *ctx_template,
 *           zio_cksum_t *zcp);
 * Wasm indirect calls typecheck; a mismatched return or arg order
 * traps with "null function or function signature mismatch". We keep
 * only fletcher4 (compiled in zcommon); the rest are never selected
 * by the demo's pool/datasets but must still link with the right
 * type. Stub body zeros zcp so any accidental use is deterministic.
 */
static inline void
zero_cksum(void *zcp)
{
    if (zcp)
        memset(zcp, 0, 32); /* sizeof(zio_cksum_t) = 4 * uint64_t */
}

void abd_checksum_sha256(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_sha512_native(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_sha512_byteswap(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_skein_native(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_skein_byteswap(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void *abd_checksum_skein_tmpl_init(const void *salt) { (void)salt; return NULL; }
void abd_checksum_skein_tmpl_free(void *tmpl) { (void)tmpl; }
void abd_checksum_edonr_native(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_edonr_byteswap(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void *abd_checksum_edonr_tmpl_init(const void *salt) { (void)salt; return NULL; }
void abd_checksum_edonr_tmpl_free(void *tmpl) { (void)tmpl; }
void abd_checksum_blake3_native(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void abd_checksum_blake3_byteswap(void *a, uint64_t s, const void *c, void *z)
{ (void)a;(void)s;(void)c; zero_cksum(z); }
void *abd_checksum_blake3_tmpl_init(const void *salt) { (void)salt; return NULL; }
void abd_checksum_blake3_tmpl_free(void *tmpl) { (void)tmpl; }

void chksum_init(void) {}
void chksum_fini(void) {}

/* ddt/brt init/fini (cut files) */
void ddt_init(void) {}
void ddt_fini(void) {}
/* enum zio_checksum is int-width in our build. */
void ddt_bp_create(int a, const void *b, const void *c, void *d) { (void)a;(void)b;(void)c;(void)d; }
void ddt_bp_fill(void *a, void *b, uint64_t c) { (void)a;(void)b;(void)c; }
void ddt_enter(void *ddt) { (void)ddt; }
void ddt_exit(void *ddt) { (void)ddt; }
int ddt_lookup(void *a, void *b, boolean_t c) { (void)a;(void)b;(void)c; return ENOENT; }
int ddt_walk(void *a, void *b, void *c) { (void)a;(void)b;(void)c; return ENOENT; }
void ddt_phys_addref(void *p) { (void)p; }
void ddt_phys_clear(void *p) { (void)p; }
void ddt_phys_decref(void *p) { (void)p; }
void ddt_phys_fill(void *a, void *b) { (void)a;(void)b; }
int ddt_phys_select(void *a, void *b) { (void)a;(void)b; return -1; }
int ddt_select(void *a, void *b) { (void)a;(void)b; return ENOENT; }
/* returns ddt_entry_t * in reality; callers are unreachable in our cut. */
void *ddt_repair_start(void *a, void *b) { (void)a;(void)b; return NULL; }
void ddt_repair_done(void *a, void *b) { (void)a;(void)b; }
void ddt_get_dedup_object_stats(void *spa, void *ddo) { (void)spa; if (ddo) memset(ddo, 0, 1); }

void brt_init(void) {}
void brt_fini(void) {}
uint64_t brt_get_dspace(void *spa) { (void)spa; return 0; }

/* bookmark hooks called by dsl_dataset / dsl_destroy */
void dsl_bookmark_block_killed(void *a, void *b, void *c) { (void)a;(void)b;(void)c; }
void dsl_bookmark_snapshotted(void *a, void *b) { (void)a;(void)b; }
void dsl_bookmark_sync_done(void *a, void *b) { (void)a;(void)b; }
int dsl_bookmark_init_ds(void *ds) { (void)ds; return 0; }
void dsl_bookmark_fini_ds(void *ds) { (void)ds; }

/* dsl_dir / dsl_dataset encryption hooks */
int dsl_dir_incompatible_encryption_version(void *dd) { (void)dd; return 0; }
void dsl_dataset_create_crypt_sync(uint64_t a, void *b, void *c, void *d, void *e) { (void)a;(void)b;(void)c;(void)d;(void)e; }
/* Real signature returns zfs_keystatus_t (enum int), single arg. */
int dsl_dataset_get_keystatus(void *dd) { (void)dd; return 0; }
void dsl_crypto_key_destroy_sync(uint64_t a, void *tx) { (void)a;(void)tx; }
void key_mapping_add_ref(void *m, void *ds) { (void)m;(void)ds; }
void key_mapping_rele(void *spa, void *m, void *ds) { (void)spa;(void)m;(void)ds; }
int spa_keystore_unload_wkey_impl(void *spa, uint64_t a) { (void)spa;(void)a; return 0; }
int spa_crypt_get_salt(void *spa, uint64_t a, void *b) { (void)spa;(void)a;(void)b; return ENOTSUP; }
/* Real signatures (dsl_crypt.h): 13 and 6 args respectively. */
int spa_do_crypt_abd(boolean_t a, void *b, const void *c, int d, boolean_t e, boolean_t f, void *g, void *h, void *i, unsigned int j, void *k, void *l, void *m) {
    (void)a;(void)b;(void)c;(void)d;(void)e;(void)f;(void)g;(void)h;(void)i;(void)j;(void)k;(void)l;(void)m;
    return ENOTSUP;
}
int spa_do_crypt_mac_abd(boolean_t a, void *b, uint64_t c, void *d, unsigned int e, void *f) {
    (void)a;(void)b;(void)c;(void)d;(void)e;(void)f;
    return ENOTSUP;
}
int spa_do_crypt_objset_mac_abd(boolean_t a, void *b, uint64_t c, void *d, void *e, boolean_t f) { (void)a;(void)b;(void)c;(void)d;(void)e;(void)f; return ENOTSUP; }

/* zio_crypt_* helpers */
void zio_crypt_copy_dnode_bonus(void *a, void *b, unsigned int c) { (void)a;(void)b;(void)c; }
void zio_crypt_decode_mac_bp(const void *bp, uint8_t *mac) { (void)bp; if (mac) mac[0]=0; }
void zio_crypt_decode_mac_zil(const void *a, uint8_t *b) { (void)a; if (b) b[0]=0; }
void zio_crypt_decode_params_bp(const void *bp, uint8_t *salt, uint8_t *iv) { (void)bp;(void)salt;(void)iv; }
void zio_crypt_encode_mac_bp(void *a, uint8_t *b) { (void)a;(void)b; }
void zio_crypt_encode_mac_zil(void *a, uint8_t *b) { (void)a;(void)b; }
void zio_crypt_encode_params_bp(void *a, uint8_t *b, uint8_t *c) { (void)a;(void)b;(void)c; }
int zio_crypt_do_indirect_mac_checksum(boolean_t a, void *b, unsigned int c, boolean_t d, uint8_t *e) { (void)a;(void)b;(void)c;(void)d;(void)e; return 0; }
int zio_crypt_do_indirect_mac_checksum_abd(boolean_t a, void *b, unsigned int c, boolean_t d, uint8_t *e) { (void)a;(void)b;(void)c;(void)d;(void)e; return 0; }
int zio_crypt_generate_iv(uint8_t *iv) { (void)iv; return 0; }
/* Real signature: 11 args. */
int zio_do_crypt_abd(boolean_t a, void *b, int c, boolean_t d, uint8_t *e, uint8_t *f, uint8_t *g, unsigned int h, void *i, void *j, void *k) {
    (void)a;(void)b;(void)c;(void)d;(void)e;(void)f;(void)g;(void)h;(void)i;(void)j;(void)k;
    return ENOTSUP;
}

/* icp / zstd init/fini */
int icp_init(void) { return 0; }
void icp_fini(void) {}
int zstd_init(void) { return 0; }
void zstd_fini(void) {}
void zfs_zstd_cache_reap_now(void) {}
size_t zfs_zstd_compress_wrap(void *s, size_t sl, void *d, size_t dl, int level) { (void)s;(void)sl;(void)d;(void)dl;(void)level; return 0; }
int zfs_zstd_decompress(void *s, void *d, size_t sl, size_t dl, int n) { (void)s;(void)d;(void)sl;(void)dl;(void)n; return 1; }
int zfs_zstd_decompress_level(void *s, void *d, size_t sl, size_t dl, uint8_t *level) { (void)s;(void)d;(void)sl;(void)dl;(void)level; return 1; }

/* gzip compress — our subset uses lz4/lzjb only */
size_t gzip_compress(void *s, void *d, size_t sl, size_t dl, int level) { (void)s;(void)d;(void)sl;(void)dl;(void)level; return sl; }
int gzip_decompress(void *s, void *d, size_t sl, size_t dl, int n) { (void)s;(void)d;(void)sl;(void)dl;(void)n; return 1; }

/* raidz/draid ops tables referenced by vdev.c's ops dispatch */
struct vdev_ops_stub { char _[512]; };
struct vdev_ops_stub vdev_raidz_ops = {{0}};
struct vdev_ops_stub vdev_draid_ops = {{0}};
struct vdev_ops_stub vdev_draid_spare_ops = {{0}};
uint64_t vdev_draid_asize_to_psize(void *vd, uint64_t psize) { (void)vd; return psize; }
int vdev_draid_read_config_spare(void *vd) { (void)vd; return ENOTSUP; }

/* host hooks — will be implemented by emscripten/libspl */
unsigned long get_system_hostid(void) { return 0x7a667357UL; }
const char *libspl_getprogname(void) { return "zfswasm"; }
int libspl_getthreadname(char *buf, size_t len) { if (buf && len) buf[0] = 0; return 0; }
int libspl_gettid(void) { return 1; }

/* channel program + delegation tables */
unsigned long zfs_lua_max_memlimit = 0;
void zfs_deleg_whokey(char *attr, int type, char checkflag, void *valp)
{ (void)type;(void)checkflag;(void)valp; if (attr) attr[0] = 0; }


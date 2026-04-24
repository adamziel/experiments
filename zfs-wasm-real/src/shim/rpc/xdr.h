/*
 * Minimal <rpc/xdr.h> for the wasm userland build.
 *
 * nvpair needs XDR typedefs and prototypes at compile time so its
 * struct nvs_ops table can compile. The XDR encode/decode paths are
 * only reached when nvlist_pack/unpack is called with NV_ENCODE_XDR.
 * The WordPress branching demo always uses NV_ENCODE_NATIVE, so the
 * bodies can be stub implementations that return FALSE. That keeps
 * our shim tiny.
 *
 * If we later need real XDR portability across architectures, swap
 * this header for libtirpc's <rpc/xdr.h> and build libtirpc to wasm.
 */
#ifndef _ZFSWASM_RPC_XDR_H
#define _ZFSWASM_RPC_XDR_H

#include <rpc/types.h>
#include <stdio.h>

enum xdr_op {
    XDR_ENCODE = 0,
    XDR_DECODE = 1,
    XDR_FREE   = 2,
};

typedef struct __rpc_xdr XDR;

struct xdr_ops {
    bool_t  (*x_getlong)(XDR *, long *);
    bool_t  (*x_putlong)(XDR *, const long *);
    bool_t  (*x_getbytes)(XDR *, char *, unsigned int);
    bool_t  (*x_putbytes)(XDR *, const char *, unsigned int);
    unsigned int (*x_getpostn)(XDR *);
    bool_t  (*x_setpostn)(XDR *, unsigned int);
    int32_t *(*x_inline)(XDR *, unsigned int);
    void    (*x_destroy)(XDR *);
    bool_t  (*x_control)(XDR *, int, void *);
    bool_t  (*x_getint32)(XDR *, int32_t *);
    bool_t  (*x_putint32)(XDR *, const int32_t *);
};

struct __rpc_xdr {
    enum xdr_op          x_op;
    const struct xdr_ops *x_ops;
    char                 *x_public;
    void                 *x_private;
    char                 *x_base;
    unsigned int         x_handy;
};

/* XDR_CONTROL operation codes referenced by nvpair.c */
#define XDR_GET_BYTES_AVAIL 1
#define XDR_HDR_LEN         2
#define XDR_DATA_LEN        3
#define XDR_MAX_LEN         4

struct xdr_bytesrec {
    bool_t xc_is_last_record;
    size_t xc_num_avail;
};

/* Primitive XDR routines — provided as stubs that return FALSE. */
bool_t xdr_char(XDR *, char *);
bool_t xdr_short(XDR *, short *);
bool_t xdr_u_short(XDR *, unsigned short *);
bool_t xdr_int(XDR *, int *);
bool_t xdr_u_int(XDR *, unsigned int *);
bool_t xdr_longlong_t(XDR *, long long *);
bool_t xdr_u_longlong_t(XDR *, unsigned long long *);
bool_t xdr_double(XDR *, double *);
bool_t xdr_string(XDR *, char **, unsigned int);
bool_t xdr_opaque(XDR *, char *, unsigned int);
/*
 * libtirpc defines xdrproc_t as (XDR *, void *, ...). Matching that
 * exactly avoids -Wincompatible-function-pointer-types against
 * OpenZFS's nvs_xdr_nvp_* encoders.
 */
typedef bool_t (*xdrproc_t)(XDR *, void *, ...);

bool_t xdr_array(XDR *, char **, unsigned int *, unsigned int,
                 unsigned int, xdrproc_t);
bool_t xdr_control(XDR *, int, void *);

void   xdrmem_create(XDR *, char *, unsigned int, enum xdr_op);

#endif /* _ZFSWASM_RPC_XDR_H */

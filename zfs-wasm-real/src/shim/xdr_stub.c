/*
 * Minimal XDR implementation for the wasm build.
 *
 * OpenZFS's vdev label is packed with NV_ENCODE_XDR, so the previous
 * all-stubs-return-FALSE version caused nvlist_pack to fail with
 * EFAULT. We implement the subset of XDR that nvpair needs:
 *
 *   - xdrmem_create for memory buffers (encode or decode)
 *   - big-endian integer put/get (32- and 64-bit)
 *   - length-prefixed + 4-byte-aligned bytes / string / opaque
 *   - array with per-element encoder
 *   - XDR_GET_BYTES_AVAIL control
 *
 * This is enough for label pack/unpack plus every feature nvlist
 * OpenZFS touches in our cut. Swap for libtirpc if we ever need the
 * full surface.
 */

#include <rpc/xdr.h>
#include <string.h>
#include <stdint.h>
#include <stdio.h>

static bool_t
xmem_putint32(XDR *x, const int32_t *ip)
{
    if (x->x_handy < 4)
        return (0);
    uint32_t v = (uint32_t) *ip;
    unsigned char *p = (unsigned char *) x->x_base;
    p[0] = (v >> 24) & 0xff;
    p[1] = (v >> 16) & 0xff;
    p[2] = (v >>  8) & 0xff;
    p[3] =  v        & 0xff;
    x->x_base += 4;
    x->x_handy -= 4;
    return (1);
}

static bool_t
xmem_getint32(XDR *x, int32_t *ip)
{
    if (x->x_handy < 4)
        return (0);
    const unsigned char *p = (const unsigned char *) x->x_base;
    uint32_t v = ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16) |
                 ((uint32_t)p[2] << 8)  |  (uint32_t)p[3];
    *ip = (int32_t) v;
    x->x_base += 4;
    x->x_handy -= 4;
    return (1);
}

static bool_t
xmem_putlong(XDR *x, const long *lp)
{
    int32_t v = (int32_t) *lp;
    return (xmem_putint32(x, &v));
}

static bool_t
xmem_getlong(XDR *x, long *lp)
{
    int32_t v;
    if (!xmem_getint32(x, &v))
        return (0);
    *lp = (long) v;
    return (1);
}

static bool_t
xmem_putbytes(XDR *x, const char *addr, unsigned int len)
{
    if (x->x_handy < len)
        return (0);
    memcpy(x->x_base, addr, len);
    x->x_base += len;
    x->x_handy -= len;
    return (1);
}

static bool_t
xmem_getbytes(XDR *x, char *addr, unsigned int len)
{
    if (x->x_handy < len)
        return (0);
    memcpy(addr, x->x_base, len);
    x->x_base += len;
    x->x_handy -= len;
    return (1);
}

static unsigned int
xmem_getpostn(XDR *x)
{
    /* Position is "bytes consumed so far" — we only know what's left;
     * callers use this to later compare via setpostn. Store as the
     * offset-from-original-base in x_private. */
    return ((unsigned int)(uintptr_t) x->x_private - x->x_handy);
}

static bool_t
xmem_setpostn(XDR *x, unsigned int pos)
{
    unsigned int total = (unsigned int)(uintptr_t) x->x_private;
    if (pos > total)
        return (0);
    x->x_base = x->x_public + pos;
    x->x_handy = total - pos;
    return (1);
}

static bool_t
xmem_control(XDR *x, int req, void *info)
{
    if (req == XDR_GET_BYTES_AVAIL) {
        struct xdr_bytesrec *r = info;
        r->xc_is_last_record = 1;
        r->xc_num_avail = x->x_handy;
        return (1);
    }
    return (0);
}

static void
xmem_destroy(XDR *x)
{
    (void) x;
}

static const struct xdr_ops xmem_ops = {
    .x_getlong  = xmem_getlong,
    .x_putlong  = xmem_putlong,
    .x_getbytes = xmem_getbytes,
    .x_putbytes = xmem_putbytes,
    .x_getpostn = xmem_getpostn,
    .x_setpostn = xmem_setpostn,
    .x_inline   = NULL,
    .x_destroy  = xmem_destroy,
    .x_control  = xmem_control,
    .x_getint32 = xmem_getint32,
    .x_putint32 = xmem_putint32,
};

void
xdrmem_create(XDR *x, char *base, unsigned int len, enum xdr_op op)
{
    x->x_op      = op;
    x->x_ops     = &xmem_ops;
    x->x_base    = base;
    x->x_handy   = len;
    x->x_public  = base;                      /* remember original base */
    x->x_private = (void *)(uintptr_t) len;   /* and original length */
}

/* -------- primitive encoders / decoders over x_ops -------- */

bool_t
xdr_int(XDR *x, int *ip)
{
    if (x->x_op == XDR_ENCODE) {
        int32_t v = *ip;
        return (x->x_ops->x_putint32(x, &v));
    } else if (x->x_op == XDR_DECODE) {
        int32_t v;
        if (!x->x_ops->x_getint32(x, &v))
            return (0);
        *ip = v;
        return (1);
    }
    return (1); /* XDR_FREE */
}

bool_t xdr_u_int(XDR *x, unsigned int *p)
{ return (xdr_int(x, (int *) p)); }

bool_t
xdr_short(XDR *x, short *sp)
{
    int v = *sp;
    if (!xdr_int(x, &v))
        return (0);
    *sp = (short) v;
    return (1);
}

bool_t xdr_u_short(XDR *x, unsigned short *p)
{ return (xdr_short(x, (short *) p)); }

bool_t
xdr_char(XDR *x, char *cp)
{
    int v = *cp;
    if (!xdr_int(x, &v))
        return (0);
    *cp = (char) v;
    return (1);
}

bool_t
xdr_longlong_t(XDR *x, long long *llp)
{
    uint32_t hi, lo;
    if (x->x_op == XDR_ENCODE) {
        uint64_t v = (uint64_t) *llp;
        hi = (uint32_t)(v >> 32);
        lo = (uint32_t)(v & 0xffffffffu);
        int32_t h = hi, l = lo;
        if (!x->x_ops->x_putint32(x, &h))
            return (0);
        return (x->x_ops->x_putint32(x, &l));
    } else {
        int32_t h, l;
        if (!x->x_ops->x_getint32(x, &h))
            return (0);
        if (!x->x_ops->x_getint32(x, &l))
            return (0);
        uint64_t v = ((uint64_t)(uint32_t)h << 32) | (uint32_t)l;
        *llp = (long long) v;
        return (1);
    }
}

bool_t xdr_u_longlong_t(XDR *x, unsigned long long *p)
{ return (xdr_longlong_t(x, (long long *) p)); }

bool_t
xdr_double(XDR *x, double *dp)
{
    long long v;
    memcpy(&v, dp, sizeof (v));
    if (!xdr_longlong_t(x, &v))
        return (0);
    memcpy(dp, &v, sizeof (*dp));
    return (1);
}

/* XDR rounds byte counts up to a multiple of 4. */
static bool_t
xdr_pad(XDR *x, unsigned int len)
{
    unsigned int pad = (4 - (len & 3)) & 3;
    static const char zeros[4] = {0, 0, 0, 0};
    char skip[4];
    if (pad == 0)
        return (1);
    if (x->x_op == XDR_ENCODE)
        return (x->x_ops->x_putbytes(x, zeros, pad));
    return (x->x_ops->x_getbytes(x, skip, pad));
}

bool_t
xdr_opaque(XDR *x, char *p, unsigned int len)
{
    if (x->x_op == XDR_ENCODE) {
        if (!x->x_ops->x_putbytes(x, p, len))
            return (0);
    } else if (x->x_op == XDR_DECODE) {
        if (!x->x_ops->x_getbytes(x, p, len))
            return (0);
    }
    return (xdr_pad(x, len));
}

bool_t
xdr_string(XDR *x, char **sp, unsigned int maxsize)
{
    unsigned int len;

    if (x->x_op == XDR_ENCODE) {
        len = (unsigned int) strlen(*sp);
        int32_t l = (int32_t) len;
        if (!x->x_ops->x_putint32(x, &l))
            return (0);
        if (!x->x_ops->x_putbytes(x, *sp, len))
            return (0);
        return (xdr_pad(x, len));
    } else if (x->x_op == XDR_DECODE) {
        int32_t l;
        if (!x->x_ops->x_getint32(x, &l))
            return (0);
        len = (unsigned int) l;
        if (len > maxsize)
            return (0);
        if (*sp == NULL) {
            *sp = (char *) malloc(len + 1);
            if (*sp == NULL)
                return (0);
        }
        if (!x->x_ops->x_getbytes(x, *sp, len))
            return (0);
        (*sp)[len] = '\0';
        return (xdr_pad(x, len));
    }
    if (*sp != NULL) {
        free(*sp);
        *sp = NULL;
    }
    return (1);
}

bool_t
xdr_array(XDR *x, char **addrp, unsigned int *sizep,
    unsigned int maxsize, unsigned int elsize, xdrproc_t elproc)
{
    int32_t l;

    if (x->x_op == XDR_ENCODE) {
        l = (int32_t) *sizep;
        if (!x->x_ops->x_putint32(x, &l))
            return (0);
    } else if (x->x_op == XDR_DECODE) {
        if (!x->x_ops->x_getint32(x, &l))
            return (0);
        *sizep = (unsigned int) l;
        if (*sizep > maxsize)
            return (0);
        if (*addrp == NULL && *sizep > 0) {
            *addrp = (char *) malloc(*sizep * elsize);
            if (*addrp == NULL)
                return (0);
            memset(*addrp, 0, *sizep * elsize);
        }
    }

    char *p = *addrp;
    for (unsigned int i = 0; i < *sizep; i++, p += elsize) {
        if (!elproc(x, p))
            return (0);
    }
    return (1);
}

bool_t
xdr_control(XDR *x, int req, void *info)
{
    if (x->x_ops && x->x_ops->x_control)
        return (x->x_ops->x_control(x, req, info));
    return (0);
}

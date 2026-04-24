/*
 * Stub XDR implementation for the wasm build.
 *
 * nvpair's XDR encode path is only taken when nvlist_pack is called
 * with NV_ENCODE_XDR. Our demo uses NV_ENCODE_NATIVE exclusively,
 * so these stubs are never reached at runtime. They exist only to
 * satisfy the linker.
 *
 * If a future caller does try to pack with XDR, every call returns
 * FALSE and the higher layers will propagate an error. That is the
 * correct behavior for an unsupported feature: fail loudly.
 */

#include <rpc/xdr.h>
#include <stdio.h>

bool_t xdr_char(XDR *x, char *p)                           { (void)x;(void)p; return (0); }
bool_t xdr_short(XDR *x, short *p)                         { (void)x;(void)p; return (0); }
bool_t xdr_u_short(XDR *x, unsigned short *p)              { (void)x;(void)p; return (0); }
bool_t xdr_int(XDR *x, int *p)                             { (void)x;(void)p; return (0); }
bool_t xdr_u_int(XDR *x, unsigned int *p)                  { (void)x;(void)p; return (0); }
bool_t xdr_longlong_t(XDR *x, long long *p)                { (void)x;(void)p; return (0); }
bool_t xdr_u_longlong_t(XDR *x, unsigned long long *p)     { (void)x;(void)p; return (0); }
bool_t xdr_double(XDR *x, double *p)                       { (void)x;(void)p; return (0); }
bool_t xdr_opaque(XDR *x, char *p, unsigned int n)         { (void)x;(void)p;(void)n; return (0); }
bool_t xdr_control(XDR *x, int req, void *info)            { (void)x;(void)req;(void)info; return (0); }
bool_t xdr_string(XDR *x, char **p, unsigned int m)        { (void)x;(void)p;(void)m; return (0); }

bool_t
xdr_array(XDR *x, char **addrp, unsigned int *sizep,
    unsigned int maxsize, unsigned int elsize, xdrproc_t elproc)
{
    (void) x; (void) addrp; (void) sizep;
    (void) maxsize; (void) elsize; (void) elproc;
    return (0);
}

void
xdrmem_create(XDR *x, char *base, unsigned int len, enum xdr_op op)
{
    x->x_op      = op;
    x->x_ops     = 0;
    x->x_base    = base;
    x->x_handy   = len;
    x->x_public  = 0;
    x->x_private = 0;
}

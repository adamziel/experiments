/*
 * Minimal <rpc/types.h> for the wasm userland build.
 *
 * Upstream OpenZFS's userland uses libtirpc's <rpc/types.h> for XDR
 * primitive typedefs. libtirpc depends on glibc internals we do not
 * want under wasm. This shim provides just enough of Sun RPC's
 * surface for nvpair_alloc_system.c and XDR helpers.
 */
#ifndef _ZFSWASM_RPC_TYPES_H
#define _ZFSWASM_RPC_TYPES_H

#include <sys/types.h>
#include <stdint.h>

#ifndef FALSE
#define FALSE 0
#endif
#ifndef TRUE
#define TRUE 1
#endif

typedef int32_t bool_t;
typedef int32_t enum_t;

/* Standard Sun RPC typedefs used by xdr_*() routines. */
typedef uint32_t rpcprog_t;
typedef uint32_t rpcvers_t;
typedef uint32_t rpcproc_t;
typedef uint32_t rpcprot_t;
typedef uint32_t rpcport_t;

#endif /* _ZFSWASM_RPC_TYPES_H */

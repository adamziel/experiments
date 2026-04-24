#ifndef _ZFS_CONTEXT_OS_H
#define _ZFS_CONTEXT_OS_H
/* wasm userland: no kernel or pthread-stack-size concerns. */
#define HAVE_LARGE_STACKS 1
#endif

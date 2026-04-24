/*
 * Stubs for libspl userland helpers that rely on Linux /proc, mount
 * tables, or other host-specific surfaces not available under wasm.
 * The WordPress branching demo does not mount filesystems or probe
 * the proc filesystem, so these routines never run at demo time.
 *
 * These replace the real implementations under libspl os linux
 * rather than trying to emulate them.
 */

#include <sys/types.h>
#include <unistd.h>
#include <errno.h>
#include <string.h>

/* gethostid(3): libzpool calls this to seed spa_get_hostid(). A
 * fixed value is fine — we never share the pool between hosts. */
long
gethostid(void)
{
    return (0x7a667357L); /* "zfsW" */
}

/* getmntany / mnttab: touched only by libzfs mount machinery, which
 * we do not include. Provide weak stubs so any stray reference
 * resolves to a no-op that returns "not found". */
struct mnttab;
int
getmntany(void *fp, struct mnttab *mp, struct mnttab *mref)
{
    (void) fp; (void) mp; (void) mref;
    errno = ENOENT;
    return (-1);
}

/* getzoneid: Solaris zone identity. libspl's sys/types.h already
 * declares zoneid_t, so we match that type directly. */
#include <sys/zone.h>
zoneid_t
getzoneid(void)
{
    return (0);
}

/* issetugid(2): not exposed by wasi-libc. libzpool uses it for
 * privilege checks; under wasm there are no set-uid bits. */
int
issetugid(void)
{
    return (0);
}

# Known Limitations

This project is a working prototype of branch-scoped preview + version
control for a WordPress site using [Dolt](https://www.dolthub.com/) for
the database and a custom PHP extension + SQLite file for the whole
WordPress tree. It boots, serves, and round-trips real WP pages, and has
an 181-assertion unit suite plus four end-to-end suites covering branch
workflow, git smart-HTTP, the homepage canary, and live-stack finding
assertions. The list below is the list of things it **does not** handle
yet — not bugs, but deliberately-deferred scope.

## Performance

### Git smart-HTTP is slow

`git clone` / `git push` take **60–120 s** against a ~3300-file WordPress
install. The server rebuilds the virtual repository from scratch on every
request (exports 12 WP tables to a single SQLite file + the vendored
sqlite-database-integration plugin + 3300 WP files via the toolkit).

Fine for individual deploys, **not a CI hot path**. The cache dir is
per-request (random suffix) so parallel operations don't collide, but
each one still pays the full rebuild cost. There's a per-request cache
of the exported `.ht.sqlite` keyed by `(branch, dolt_hash)` so the two
HTTP calls of a single `git clone` see byte-identical bytes, but the
WP file tree and the git commit chain are still rebuilt each time.
Future work: memoize the full tree between requests, invalidate on
branch-state changes.

### Static asset serving goes through PHP

Every `.png`, `.css`, `.js` request goes through `php -S` and through the
SQLite store. There's no nginx/httpd front, no extraction cache. For
individual-developer preview this is acceptable. For any kind of real
traffic you'd want a discardable on-disk cache keyed by `(branch,
blob_hash)` that a real web server can serve directly.

### WordPress cold-start per request

`php -S` is single-process and forks a child per request. OPcache lives
inside that child, so its cache warms up and dies per request. First
request through a branch is always slow (tens of seconds); subsequent
requests are cached by Linux file cache but not by OPcache across
children. Acceptable for dev; use PHP-FPM for anything heavier.

## Filesystem layer (branchfs extension)

### No GC trigger — manual `branchctl gc`

Deleted branches leave their blob refs orphaned in `blobs` until the user
runs `branchctl gc`. No automatic garbage collection on branch delete.

### No file-level diff UI

The branchfs overlay tracks per-branch copy-on-write at the path level,
but there's no tool that shows "what files changed between these two
branches." `bin/branchctl diff` only shows row-count diffs for Dolt
tables and total file counts for branchfs overlays — not per-file
differences. Mitigation: use `git clone` + `git diff` on two branches
for a true per-file view.

### File merge is "fork-point or ours|theirs"

`scripts/merge.php` does a 3-way merge against the fork-point fs_commit
of the source branch. If both branches modified the same path after
divergence, conflict is surfaced and the merge refuses unless you pass
`--strategy=ours` or `--strategy=theirs`. There's no interactive merge
UI; there's no per-hunk resolution. For file-content merging, use
`git pull` on a clone and resolve locally, then `git push` back.

### No symlinks or special files

`link()`, `symlink()`, `readlink()`, `linkinfo()` are no-ops for branchfs
paths (return `false` / `0`). WordPress doesn't need symlinks in normal
operation; a plugin that relies on symlinks would break. Same for named
pipes, sockets, block devices.

### SQLite is single-writer

WAL mode allows concurrent readers + one writer. Under very high write
concurrency (many simultaneous pushes or plugin installs), you'll see
`database is locked` errors after the 5 s busy-timeout. No fix other
than serializing writers or moving to a real DB for the file store.

## Database layer (Dolt)

### Schema changes must travel with rows

If a push changes `schema.sql` (adds a column, etc.) AND changes rows in
the same commit, the `ALTER TABLE` runs before the row diffs. Dropping
a column that's referenced by new rows in the same commit will fail.

### No transient filter on arbitrary tables

`wp_options` transient rows are filtered out of the exported SQLite
file, but other potentially-noisy tables (e.g. `wp_actionscheduler_*`
if the Action Scheduler plugin is used) are exported verbatim and
every push will diff them. Future work: pluggable ignore list.

### SQLite-integration plugin version is pinned

The sqlite-database-integration plugin is vendored under
`vendor/sqlite-database-integration/` (a snapshot of upstream, not a
submodule). Upgrading pulls a new plugin set into every future clone;
it does not migrate already-distributed clones. If the upstream plugin
changes how it translates MySQL DDL to SQLite in a breaking way, old
clones may fail to boot against the new server-exported SQLite file
until re-cloned.

### Schema migrations are column-diff only

The push path emits `ALTER TABLE ADD/DROP COLUMN` on Dolt when the
pushed SQLite file has extra or missing columns relative to the Dolt
table. If BOTH added AND dropped columns appear in the same push it
refuses (renames and complex type-narrowing changes require explicit
server-side migration).

### BLOB round-trip via UTF-8 sniffing

`LONGBLOB`-typed columns that happen to hold binary bytes get stored
as SQLite `BLOB` (detected by a UTF-8 validity sniff); text-shaped
blobs go in as `TEXT`. Mixed-content columns where some rows are
binary and others text will work, but the classification is per-row.
Columns that hold exactly `4 GiB − 1 B` or larger individual values
exceed what we currently attempt to stream through PHP memory — no
hard limit enforced, but memory will be the wall.

### No multi-DB / multisite

Everything assumes a single Dolt database named `wordpress`. WordPress
multisite installs use `wp_<N>_*` tables and cross-table references
that the exporter doesn't understand.

## Git protocol

### Push auth is HTTP basic only

No SSH, no OAuth, no per-key credentials. `BRANCHFS_GIT_USER` and
`BRANCHFS_GIT_PASSWORD_HASH` env vars (single user, single password).
`BRANCHFS_PROD=1` mode enforces non-default creds but doesn't add any
kind of multi-user ACL.

### Reserved branch names are hardcoded

`www`, `admin`, `api`, `mail`, `localhost`, `wp` are rejected to
prevent subdomain shadowing. The list isn't configurable.

### Client IP / CIDR blocking not implemented

Anyone who can reach the PHP port can clone. For push, they need
credentials. For clone, no restrictions.

## Development-mode defaults

### WordPress has external HTTP blocked

`wp-config.php` sets `WP_HTTP_BLOCK_EXTERNAL` so plugin/core update
checks don't phone home during dev. Side effect: noisy
"could not establish secure connection to WordPress.org" warnings in
`wp-debug.log` on every request. **Expected**, not a bug.

### Hardcoded admin / admin credentials

The bootstrap installs WP with user `admin` / password `admin`. Anyone
with HTTP access to dev can log in. Useful for local testing, a
disaster for anything exposed.

### `/etc/hosts` entries required

Subdomain routing assumes `<branch>.wp.localhost` resolves to
`127.0.0.1`. `/etc/hosts` doesn't wildcard, so each branch you want to
visit in a browser needs a line. Alternatives: dnsmasq with wildcard,
or use `curl -H "Host: <branch>.wp.localhost"`.

## OS / runtime assumptions

### Supported tier-1 targets (design — **not yet validated on CI**)

The release pipeline (`.github/workflows/branched-wp-release.yml`)
targets these four platforms for every tag:

| OS | Arch | Libc | Dolt build |
| --- | --- | --- | --- |
| Linux | x86_64  | musl (static) | `dolt-linux-amd64` |
| Linux | aarch64 | musl (static) | `dolt-linux-arm64` |
| macOS | x86_64  | Apple libSystem | `dolt-darwin-amd64` |
| macOS | aarch64 | Apple libSystem | `dolt-darwin-arm64` |

`ext/branchfs.c` uses only POSIX APIs (`fnmatch(3)`, `realpath(3)`,
`<sys/stat.h>`, `<dirent.h>`) available on Linux (glibc + musl) and
Darwin. On macOS the `Makefile` switches the linker to produce a
`-bundle` with `-undefined dynamic_lookup` so undefined PHP/Zend
symbols resolve at `dlopen()` time — but only in local-dev mode.

The release pipeline compiles branchfs *into* the static PHP binary
as a first-class static-php-cli extension (see
`branched-wp/ci/spc-branchfs/`) — musl-static PHP is built without
`HAVE_LIBDL`, so `dlopen()`-based loading of an external `.so` is
impossible on the Linux legs. The builtin approach sidesteps that
entirely and also removes an extra file from the shipped bundle.
The Makefile's `.so` build and `-d extension=...` entrypoints are
retained for local development with a dynamically-linked Homebrew /
apt PHP; `e2e/dev.sh` and the test harness auto-detect which mode
is in effect.

Other Unix-likes (FreeBSD, OpenBSD, Solaris-family) *should* work in
theory but aren't part of CI; run at your own risk.

### Windows: not yet supported

The branchfs C extension, its Makefile, and the gitpress packaging
don't target Windows. Port blockers:

- **No native `fnmatch(3)`.** Windows CRT doesn't expose it; we'd
  need to vendor a BSD-licensed polyfill (e.g. from musl or the
  Android Bionic tree).
- **`realpath(3)` vs `_fullpath` / `GetFullPathNameW`.** Semantics
  differ (symlink resolution, case folding, drive-letter roots). The
  wrapper that overrides PHP's `realpath()` would need a Windows
  branch that translates between POSIX-shaped virtual paths and
  Windows canonicalization.
- **Build system divergence.** PHP on Windows uses MSVC + a
  `phpize`-less `configure.js` / `config.w32` pipeline. Our current
  `config.m4` + glibc-friendly `Makefile` can't be retargeted with a
  cross-compiler; a separate MSBuild/NMake config needs to be
  written.
- **Path separator + case-insensitive semantics.** The overlay
  assumes forward slashes and case-sensitive lookups; both
  assumptions are baked into the SQLite `files` table layout and the
  `branchfs://` URL parser. A real port would need a compatibility
  layer in `store_*` and everywhere `"/"` is used as a separator.

Tracking placeholder: `WP_BRANCHED_WINDOWS_ISSUE` — no GitHub issue
is filed yet; search for this string when one is opened.

### PHP 8.2 only

Built against PHP 8.2 headers. Not tested on 8.3+. Zend ABI isn't
stable across major versions.

### Docker on Mac is slow for bind mounts

The image doesn't bind-mount `e2e/wp-src/` (WordPress source) because
Docker-Desktop-on-Mac's filesystem proxy makes the 2400-file tree
reading painful. WordPress source lives in the image; only the project
source is bind-mounted.

## Testing

### OPcache regression canary is live-stack only

`e2e/test_homepage_renders.sh` is the regression test that catches
"site renders blank." It needs a live dev stack to run (Dolt +
php -S + WP install). There isn't a unit-test level equivalent —
testing that bytes actually flow through the stream-wrapper + Zend
engine requires running the engine.

### No cross-version WordPress matrix

Everything is pinned to WordPress 6.5. Later minors / 6.6+ weren't
tested. Core changed the pattern registration path a couple of times;
a version bump may need new work.

## Not implemented

- Admin UI for branch management (branchctl is CLI-only).
- Branch ACL / per-user branch access.
- Webhooks on push / commit (useful for deploy triggers).
- Preview banner / admin-bar hint that you're on a non-main branch
  (the response does carry `X-BranchFS-Branch:` for debugging).
- Automated Dolt remote push (branchctl doesn't `push` to a Dolt remote;
  Dolt backups are a separate concern).
- Background job for periodic `branchctl gc` / Dolt housekeeping.
- Schema migrations tooling (if you alter `fs_commits` schema, existing
  stores need a migration; today the extension just auto-creates tables
  if missing but won't migrate columns).

## See also

- `README.md` — project overview, installation, workflow.
- `.github/workflows/branched-wp.yml` — CI surface.
- `e2e/` — end-to-end scripts (run_e2e, test_git_protocol,
  test_sqlite_clone, test_findings_live, test_homepage_renders).
- `tests/` — unit test suites.

## Git round-trip caveats

### Each push causes the remote's git commit hash to rotate

Server-generated commits embed `Dolt-Commit: <hash>` in the message,
so after a successful push the server's `main` hash advances — even
though the underlying tree is equivalent to what the client just
pushed. A second push from the same clone without an intervening
`git pull --rebase` will be rejected as non-fast-forward. Clone, edit,
push, *pull*, edit, push — that's the round-trip loop.

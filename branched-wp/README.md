# branched-wp

Prototype of a **branch-scoped preview system** for a WordPress production site
using [Dolt](https://www.dolthub.com/) for the database and a custom PHP
extension + single SQLite file for the entire WordPress filesystem tree.

The idea: turn a WordPress site into a tiny on-disk launcher + one SQLite file
holding core, plugins, themes, and uploads. A signed preview cookie activates a
branch; admins can preview staged changes on the production host without
customers seeing them, then merge back.

## Status

Prototype. The PHP extension is ~1400 lines of C and the full test suite
(92/92) passes, including a `test_plugin_compat.php` that specifically covers
the "plugins use relative/absolute/`__DIR__` paths, not `branchfs://` URLs"
constraint. It is **not** production-ready — see *Known rough edges* below.

## What's here

| Path | Purpose |
| --- | --- |
| `ext/branchfs.c`, `ext/branchfs.h`, `ext/config.m4` | PHP extension source |
| `sql/schema.sql` | SQLite schema: content-addressed `blobs`, `branches`, per-branch COW `files` overlay with tombstones |
| `scripts/launcher.php` | Single real on-disk file — verifies a signed cookie/header, activates the branch, boots WordPress from the SQLite store |
| `scripts/import_wp.php` | Imports an existing WordPress directory tree into the store |
| `scripts/init_db.php` | Creates an empty store |
| `scripts/merge.php` | Coordinated Dolt DB merge + 3-way file merge |
| `wp-plugin/branchfs-wp.php` | WordPress mu-plugin: `pre_move_uploaded_file` hook, `filesystem_method` override, `CALL DOLT_CHECKOUT` on `init`, admin-bar branch indicator |
| `tests/test_basic.php` | Wrapper + SQLite store + branch ops |
| `tests/test_plugin_compat.php` | **The plugin-compat constraint** — relative paths, `__DIR__`, absolute `/var/www/html/...` paths, `include`/`require`, `scandir`, `is_dir`, `file_exists`, `file_put_contents`, `rename` |
| `tests/test_wp_boot.php` | Boots a simulated WordPress from the store and checks branch isolation |
| `Makefile` | Build + test targets |

## How the plugin-compat trick works

A plain `branchfs://` stream wrapper would only intercept functions that are
called with a `branchfs://...` URL. WordPress plugins don't do that — they use
`__DIR__`, `plugin_dir_path()`, `ABSPATH . 'wp-...'`, bare relative paths, and
sometimes absolute paths like `/var/www/html/wp-content/...`. The extension
therefore does two things:

1. Registers `branchfs://` as an explicit wrapper (for the launcher).
2. **Overrides the plain `file` wrapper** so that when a path under the
   configured WP root shows up (relative or absolute, with no scheme), all of
   `stream_opener`, `url_stat`, directory ops, `unlink`, `rename`, `mkdir`,
   `rmdir`, and `stream_metadata` route through the SQLite store.
3. Replaces `file_exists()` in the function table because `access(2)` bypasses
   PHP stream wrappers, so `file_exists()` would otherwise miss the virtual
   tree.

The PHP stat cache is cleared on every mutation so stale `stat()` results don't
leak between branches.

## Building & running locally

Requires PHP 8.2 dev headers and SQLite 3 dev headers. On Debian/Ubuntu:

```bash
sudo apt-get install php8.2-dev libsqlite3-dev build-essential
```

Edit `Makefile` and set `PHP_DEV_DIR` / `SQLITE_INC` / `SQLITE_LIB` to match
your system (the committed values are the Nix store paths from the machine
this was developed on — they will not work as-is elsewhere).

Then:

```bash
make                    # builds ext/branchfs.so
make test-all           # runs all three test suites (92 assertions)
```

Each test script is self-contained — it creates a temporary SQLite store in
`/tmp/`, populates a fake WP tree, activates interception, and makes
assertions. No database server, no web server, no WordPress install required
just to see the extension working.

To try it with a real WordPress:

```bash
# 1. Create an empty store
make init-db                                          # -> /tmp/branchfs.db

# 2. Import a WordPress tree
php -d extension=$PWD/ext/branchfs.so \
    scripts/import_wp.php /path/to/wordpress /tmp/branchfs.db main

# 3. Point your web server at scripts/launcher.php
#    Set env vars: BRANCHFS_DB, BRANCHFS_WP_ROOT, BRANCHFS_COOKIE_SECRET
```

## How previews work end-to-end

1. Default traffic: launcher sees no preview cookie → activates branch `main` →
   WordPress boots, reads files from the `main` overlay in SQLite, connects to
   Dolt with `DB_NAME = "$base/main"`.
2. Admin creates a preview: `branchfs_create_branch('staging', 'main')` +
   `dolt_branch('staging', 'main')`.
3. Admin gets a signed cookie `staging.<expires>.<hmac>`.
4. Next request with that cookie → launcher verifies HMAC → activates branch
   `staging` → WordPress sees the staging overlay + Dolt's `staging` branch.
5. All writes (plugin installs, content edits, uploads) land in the `staging`
   overlay and the Dolt `staging` branch. Customers still on `main` see
   nothing.
6. `scripts/merge.php` does a coordinated merge: Dolt merge for the DB, 3-way
   file merge for the SQLite store (at path granularity, with base = branch
   creation point).

## Known rough edges

Flagged by the adversarial verifier — worth fixing before real use:

- `scripts/merge.php:37-38` has a SQL-quoting bug: `$db->escapeString("'$source'")`
  wraps the value in single quotes *before* escaping. Currently only touched
  by CLI args, but fragile.
- `wp-plugin/branchfs-wp.php` uses `$wpdb->query("CALL DOLT_CHECKOUT('$branch')")`
  with string interpolation. The branch name is regex-validated
  (`[a-zA-Z0-9_\-\/\.]{1,128}`) so it's safe today, but should be a prepared
  statement.
- `Makefile` paths are Nix-specific — tweak for your system.
- OPcache: paths under the `file://` wrapper will share OPcache keys across
  branches. Either set `opcache.validate_timestamps=1` with aggressive
  invalidation on branch switch, or gate branch switching with a pool restart.
  The launcher should probably invalidate OPcache entries for changed PHP
  files after a merge.
- No extraction cache for hot static assets. Serving large images through
  PHP-FPM + SQLite will bottleneck — a discardable per-`(branch, blob_hash)`
  cache on disk is the obvious next step.
- DoltLite was considered as a single-file alternative to SQLite + Dolt but
  rejected as alpha / single-player / no remotes.

## How this was built

Built in one shot via an adversarial-loop (implementer Claude + independent
verifier Claude), which converged on the first iteration. The verifier
specifically checked the plugin-compat constraint with
`tests/test_plugin_compat.php` before passing.

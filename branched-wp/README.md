# branched-wp

Prototype of a **branch-scoped preview system** for a WordPress production site
using [Dolt](https://www.dolthub.com/) for the database and a custom PHP
extension + single SQLite file for the entire WordPress filesystem tree.

The idea: turn a WordPress site into a tiny on-disk launcher + one SQLite file
holding core, plugins, themes, and uploads. A signed preview cookie activates a
branch; admins can preview staged changes on the production host without
customers seeing them, then merge back.

## Limitations & known gaps

See [LIMITATIONS.md](LIMITATIONS.md) for everything this prototype does
not handle yet — performance caveats, filesystem/DB constraints, auth
model, and un-implemented features. **If something behaves unexpectedly,
check there first.**

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

## Git Smart-HTTP: clone/pull/push an entire WordPress site

Developers can `git clone http://wp.localhost:18080/site.git` to get BOTH the
file tree and the DB state, edit locally, then `git push` changes back. One
git branch per branchfs+Dolt branch; each git commit is paired with an
`fs_commits` snapshot + a Dolt commit.

### Endpoints (served by `scripts/git_server/server.php`, mounted in `e2e/router.php`)

```
GET  /<site>.git/info/refs?service=git-upload-pack    # clone/fetch discovery
POST /<site>.git/git-upload-pack                      # serve clone/fetch
GET  /<site>.git/info/refs?service=git-receive-pack   # push discovery
POST /<site>.git/git-receive-pack                     # accept push (HTTP basic auth)
```

### Usage

```bash
# Clone the full site (files + DB as NDJSON)
git clone http://wp.localhost:18080/site.git

# Push changes back (requires basic auth)
git push http://admin:admin@wp.localhost:18080/site.git main

# Push to a new branch — creates it on branchfs + Dolt + the live subdomain
git push http://admin:admin@wp.localhost:18080/site.git main:marketing
```

### Repository layout inside the clone

```
site.git/
  wordpress/          # full WP file tree (sourced from branchfs overlay)
    wp-admin/ wp-content/ wp-includes/ index.php ...
  db/                 # Dolt tables, NDJSON sorted by primary key
    schema.sql        # DDL dump
    wp_options.ndjson wp_posts.ndjson wp_postmeta.ndjson
    wp_users.ndjson wp_usermeta.ndjson
    wp_comments.ndjson wp_commentmeta.ndjson
    wp_terms.ndjson wp_termmeta.ndjson
    wp_term_relationships.ndjson wp_term_taxonomy.ndjson
    wp_links.ndjson
```

Binary column values (`bytes` type) are base64-encoded in NDJSON.

### How it flows

**Clone/pull:** if the branch's overlay has diverged from the last `fs_commits`
row, auto-snapshot + auto-`DOLT_COMMIT` first so nothing transient gets
missed. Then build the virtual tree from `fs_commit_files` + live Dolt
SELECTs and serve it through php-toolkit's Git endpoint.

**Push:** parse the incoming packfile, diff each commit's tree against its
parent, apply file changes to the branchfs overlay and DB changes as
INSERT/UPDATE/DELETE against the target Dolt branch. Record a paired
`fs_commits` + `DOLT_COMMIT`. `schema.sql` changes apply as `ALTER TABLE`
before row changes. Auth is HTTP basic; username becomes the Dolt commit
author. **Push is transactional**: if any apply step fails (bad NDJSON,
schema mismatch, Dolt error, reserved branch name), the git ref is rewound,
`DOLT_RESET --hard` restores the pre-push Dolt HEAD, and the client sees a
500 with the failure message — no half-applied state.

### Auth

- **Read access** (`upload-pack`): anonymous.
- **Push access** (`receive-pack`): HTTP basic. Default dev credentials are
  `admin` / `admin`. Override via env on the PHP server:
  - `BRANCHFS_GIT_USER=<username>`
  - `BRANCHFS_GIT_PASSWORD_HASH=<php password_hash() output>` —
    generate with `php -r "echo password_hash('your-pass', PASSWORD_DEFAULT);"`
  - `BRANCHFS_PROD=1` switches the server to production-mode auth: pushes
    are refused unless both env vars are set, and the default
    `admin/admin` is no longer accepted.

### Reserved branch names

Branch names `www`, `admin`, `api`, `mail`, `localhost`, `wp` are refused by
both `bin/branchctl create` and `git push <…>:<reserved-name>` — those
labels would shadow router host parsing and silently route traffic to the
wrong overlay.

### NDJSON layout details

- **Sorted by primary key** so diffs are deterministic.
- **Tables with > 5000 rows are partitioned** as `<table>-NNNN.ndjson`
  chunks of 5000 each (e.g. `wp_posts-0001.ndjson`, `wp_posts-0002.ndjson`).
  Small tables stay as a single `<table>.ndjson`. The push handler
  concatenates all `<table>*.ndjson` parts in order before diffing.
- **Transients are filtered out** of `wp_options.ndjson`: rows whose
  `option_name` matches `_transient_%`, `_site_transient_%`, or
  `_transient_timeout_%` are omitted on export and preserved across pushes
  (the import step skips them when computing deletes), so transient churn
  doesn't show up as spurious diffs.
- **Binary BLOB columns** are base64-encoded; the push side decodes back.

### Library

Built on [WordPress/php-toolkit](https://github.com/wordpress/php-toolkit)'s
Git component, vendored at `vendor/wordpress-php-toolkit/`. Four root-commit
/ initial-clone bugs needed fixing in the toolkit; fixes are upstream via
**[WordPress/php-toolkit#235](https://github.com/WordPress/php-toolkit/pull/235)**:

1. `GitEndpoint::handle_fetch_request` used `isset($parsed_commit->parents)`,
   which is always true because `ParsedCommit::$parents` is initialised to
   `[]` — the root-commit branch never fired. Switched to `empty()`.
2. `GitEndpoint::handle_fetch_request` called `find_objects_added_in()` with
   an ancestor hash as the second argument, but that parameter is `$options`.
   Changed to `find_objects_added_since()`.
3. `GitRepository::find_objects_added_in` dereferenced
   `get_first_parent_hash()` unconditionally, which returns `null` for root
   commits. Falls back to `Commit::NULL_HASH` when `parents` is empty.
4. `GitRepository::get_commits_range` threw on `NULL_HASH` ancestor (initial
   clone case). Added an explicit branch that walks the full reachable set.

Until the PR merges, changes live in `vendor/wordpress-php-toolkit/`.

### Testing

Two end-to-end suites cover the git protocol against a live dev stack:

- **`e2e/test_git_protocol.sh`** — 13 acceptance steps: clone, log
  inspection, file edits, DB row edits, push, new-branch push, subdomain
  verification. Idempotent — re-runs without manual cleanup.
- **`e2e/test_findings_live.sh`** — 17 assertions: parallel clones,
  push-rejection on bad NDJSON (rollback verified), custom-creds auth,
  backward-reset-then-edit-then-commit, reserved branch names, duplicate
  create exits cleanly.

```bash
bash e2e/dev.sh &                  # in one shell
bash e2e/test_git_protocol.sh      # in another
# -> RESULTS: 13 passed, 0 failed out of 13
bash e2e/test_findings_live.sh
# -> RESULTS: 17 passed, 0 failed
```

**Note on `wp-debug.log` warnings:** `wp-config.php` defines
`WP_HTTP_BLOCK_EXTERNAL` to keep WordPress from reaching out to
wordpress.org / api.wordpress.com / Gravatar during dev. As a side effect
`wp-debug.log` will contain noisy "could not establish secure connection
to WordPress.org" warnings — those are **expected** and don't indicate a
problem.

### Performance note

The git server rebuilds a virtual repository on every request from the
current branchfs + Dolt state — exporting 13 WP tables and ~3300 files.
On a default WP install this is roughly 60-120 s per clone or push.
Acceptable for individual deploys; not a CI hot path. The cache directory
is per-request (random suffix) so concurrent operations don't collide.

## Quickest path: Docker (works on Mac)

A `Dockerfile` and `docker-compose.yml` are included. The image contains the
build toolchain (PHP 8.2, libsqlite3-dev, Dolt, pre-fetched WordPress 6.5)
and **bind-mounts this repo at `/app`** — so editing files on your Mac takes
effect immediately, no image rebuild. When `ext/branchfs.c` changes, the
container entrypoint re-runs `make` before starting. Works on Apple Silicon
(arm64) and Linux (amd64) — `TARGETARCH` picks the right Dolt binary.

Build the image once (only needs to be redone if the Dockerfile changes):

```bash
docker compose build
```

### Live WordPress you can click around in

```bash
docker compose up dev
```

Then from your Mac browser:

- **Main site**: <http://localhost:18080/>
- **Admin** (`admin` / `admin`): <http://localhost:18080/wp-login.php>

Ctrl+C to stop. The script prints a recipe for creating a preview branch.

### Run the automated 8-step e2e

```bash
docker compose run --rm e2e       # expect: E2E RESULT: 8/8 steps pass
```

### Interactive shell (with ports forwarded)

```bash
docker compose run --rm --service-ports shell
```

### Subdomain-based branch routing

No cookies — **the subdomain IS the branch**:

| URL                                        | Branch       |
| ------------------------------------------ | ------------ |
| `http://wp.localhost:18080/`               | `main`       |
| `http://marketing.wp.localhost:18080/`     | `marketing`  |
| `http://feature.wp.localhost:18080/`       | `feature`    |

The router reads `Host:` and picks the first dot-label as the branch name. The
root host is controlled by `BRANCHFS_ROOT_HOST` (default `wp.localhost`).

On your Mac add the hosts once — `/etc/hosts` doesn't do wildcards, so list
each subdomain you plan to use:

```
# /etc/hosts
127.0.0.1   wp.localhost  marketing.wp.localhost  feature.wp.localhost
```

(Or run dnsmasq for true wildcarding.) Then `docker compose up dev` and visit
the URLs above in a browser.

### Managing branches: `bin/branchctl`

Every command targets both the branchfs filesystem overlay and the Dolt
branch in one shot. Lookups default to `BRANCHFS_DB=/tmp/branchfs-dev/branchfs.db`
and Dolt on `127.0.0.1:13306` (override via `--db`, `--dolt-host`,
`--dolt-port`).

```
list                      # all branches: overlay file count + Dolt presence
show    <name>            # overlay metadata + Dolt HEAD (hash, author, msg)
create  <name> [--from P] # fork overlay + Dolt branch in one step
delete  <name>            # drop overlay + Dolt branch (main is protected)

commit  <name> [-m "msg"] # DOLT_ADD -A + DOLT_COMMIT on <name>
log     <name> [-n N]     # full commit history on that branch (Dolt)
diff    <a> <b>           # per-table row adds/dels/mods + overlay file counts
merge   <from> --into <to># 3-way file merge + DOLT_MERGE; lands on <to>
reset   <name> <commit>   # DOLT_RESET --hard on that branch (DB only)
rollback <name>           # shortcut: reset <name> HEAD~1
```

**Typical flow**:

```bash
docker compose exec dev bin/branchctl create feature --from main

# Edit things on http://feature.wp.localhost:18080/wp-admin/ ...
docker compose exec dev bin/branchctl commit feature -m "homepage tweak"

# Inspect history
docker compose exec dev bin/branchctl log feature -n 5
# COMMIT                              WHEN                 AUTHOR  MESSAGE
# u1j1lsajrf03350rquo7egm7vjjt7479    2026-04-15 18:40:56  root    homepage tweak
# hbolljopqf13h0a1ph21vjup5hn9ubla    2026-04-15 18:37:52  root    Initial WordPress install

# Compare against main
docker compose exec dev bin/branchctl diff main feature
# dolt: rows changed 'main' -> 'feature'
# TABLE       ADDED  DELETED  MOD
# wp_options      0        0    1
# branchfs: overlay file counts
#   'main' overlay       3309 files
#   'feature' overlay      12 files

# Ship it
docker compose exec dev bin/branchctl merge feature --into main

# Or back out the last commit
docker compose exec dev bin/branchctl rollback feature

# Or jump to a specific historical commit
docker compose exec dev bin/branchctl reset feature u1j1lsajrf03350rquo7egm7vjjt7479
docker compose exec dev bin/branchctl delete feature
```

**Caveats**:

- `reset` and `rollback` rewind BOTH Dolt AND the file overlay — each
  `branchctl commit` records an `fs_commits` snapshot paired with the new
  Dolt hash, so reset restores the file tree from the snapshot. `log`
  marks commits that have a paired snapshot (`*` in the FS column).
  Reset/rollback refuse to run if the branch has uncommitted file-side
  changes; pass `--force` to discard them.
- `merge` conflicts: Dolt surfaces them through `dolt_conflicts`. `merge.php`
  prints "WARNING: conflict on table X" when any are detected; resolve via
  standard Dolt SQL (update/delete from the conflict tables) before the
  next commit. File-side conflicts (same path modified on both branches
  with different bytes) currently resolve "source wins".
- No branch *switching* — the subdomain in the URL is the source of truth.
  `main` is protected from deletion.

### How it works end-to-end

1. Request arrives at `php -S`. Router reads `Host:` → picks branch name.
2. Router calls `branchfs_set_branch($name); branchfs_activate()`. All
   filesystem calls (including `realpath`, `is_file`, etc.) now resolve
   against the branch's copy-on-write overlay in the SQLite store.
3. WP's `wp-config.php` reads `branchfs_get_branch()` and sets
   `DB_NAME = "wordpress/$branch"`, so MySQL connects Dolt on that branch.
4. WP boots, sees branch-specific files + branch-specific DB rows,
   renders the page. The response includes an `X-BranchFS-Branch:` header
   for debugging.

## Building & running natively

Requires PHP 8.2 dev headers and SQLite 3 dev headers. On Debian/Ubuntu:

```bash
sudo apt-get install php8.2-dev libsqlite3-dev build-essential pkg-config
```

The `Makefile` auto-detects includes via `php-config` and `pkg-config`, so
`make` should just work. On Nix or other non-standard setups you can still
override:

```bash
make PHP_DEV_DIR=/nix/.../php-8.2-dev \
     SQLITE_INC=/nix/.../sqlite-dev/include \
     SQLITE_LIB=/nix/.../sqlite/lib
```

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

## End-to-end test against real WordPress + real Dolt

`e2e/run_e2e.sh` is a self-contained integration test that exercises the
whole stack for real (no mocks):

1. Downloads/uses WordPress 6.5 from `e2e/wp-src/`, starts `dolt sql-server`
   on a local port, creates an empty branchfs SQLite store, imports WP into
   `main`, installs WordPress via `wp_install()`.
2. Launches `php -S` with `e2e/router.php` as the front controller, loading
   the `branchfs.so` extension.
3. Runs 8 HTTP-level assertions against the live server:
   - **Step 1** — `GET /` on `main` returns 200 and the installed site title.
   - **Step 2** — creates branch `preview-a` in both Dolt and branchfs.
   - **Step 3** — `GET /` with signed `wp_preview` cookie reflects the
     modified title and a marker in `themes/twentytwentyfour/style.css`;
     `GET /` without the cookie still returns main, unchanged.
   - **Step 4** — `scripts/merge.php` coordinates Dolt merge + 3-way file
     merge; main now reflects preview-a.
   - **Step 5** — `dolt_revert` + file revert restores main.
   - **Step 6** — discards `preview-b` (deletes Dolt branch + branchfs
     overlay) and confirms it's no longer resolvable.
   - **Step 7** — three parallel branches (`preview-c`, `preview-d`,
     `preview-e`) each with different title + marker; parallel curl
     requests with branch-specific cookies return only that branch's
     content, no cross-contamination.
   - **Step 8** — scans `e2e/server.log` for PHP fatals / SQLite
     corruption; fails if any.

Requires `dolt` on PATH. Run:

```bash
export PATH=~/.local/bin:$PATH    # or wherever dolt lives
bash e2e/run_e2e.sh
```

Sample passing run:

```
STEP 1 PASS: main / returns 200 with 'Branched WP'
STEP 2 PASS: created branch preview-a in dolt and branchfs
STEP 3 PASS: preview-a / reflects modified title 'Preview A Site'
STEP 3 PASS: preview-a stylesheet contains '/* preview-a marker */'
STEP 3 PASS: main / unchanged (no cookie)
STEP 4 PASS: merge preview-a -> main completed, main reflects changes
STEP 5 PASS: revert restored main to original state
STEP 6 PASS: discarded preview-b, branch no longer resolvable
STEP 7 PASS: three parallel branches return independent content
STEP 8 PASS: no PHP fatals or SQLite corruption in server log

E2E RESULT: 8/8 steps pass
```

The script kills its own processes on exit and picks ports deterministically;
re-running from scratch is idempotent.

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

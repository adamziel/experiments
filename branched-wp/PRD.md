# ForkPress — Product Requirements Document

## Overview

ForkPress is a branch-scoped WordPress preview system. A site is a single
portable `.fp` file. The `forkpress` binary boots a full WordPress stack from
that file; the `branchfs` PHP extension makes the same file work on production
PHP servers without the binary.

---

## Deliverables

### D1 — `forkpress` binary
Single static binary per target, no runtime dependencies.

| Target | Linking |
|--------|---------|
| `x86_64-unknown-linux-musl` | fully static |
| `aarch64-unknown-linux-musl` | fully static |
| `x86_64-apple-darwin` | libSystem only |
| `aarch64-apple-darwin` | libSystem only |

Built via `make dist` (static-php-cli + Rust workspace).

### D2 — `branchfs` PHP extension
C extension (`ext/branchfs.c`) loadable on any standard PHP 8.x installation.
Provides the filesystem virtualisation layer independently of the full binary,
so production sites can install only the extension.

Public API:
```c
branchfs_set_db(string $path): void      // open a .fp file
branchfs_set_branch(string $name): void  // activate a branch
```

Overrides the PHP `file` stream wrapper so all file I/O under the WP root is
served from the SQLite store transparently.

---

## Site file format

### SF1 — Single `.fp` file
The entire site lives in one SQLite file (WAL mode).

| Table group | Contents |
|-------------|----------|
| `blobs`, `blob_chunks`, `branches`, `files`, `fs_commits`, `fs_commit_files` | WordPress filesystem (COW) |
| `b{id}_wp_*` tables | WordPress database, one set of tables per branch |
| `site_config`, `users` | Site-wide config and authentication |

WAL mode guarantees every committed write is durable even on crash — no
pack/unpack cycle, no temp state.

**Chunked blob storage.** Blobs larger than 1 MB are split across rows in
`blob_chunks(blob_hash, chunk_no, data)`. The `blobs` row keeps
`(hash, NULL, size)` as a metadata pointer, and the payload is streamed
into 1 MB slices. Small blobs (≤ 1 MB) stay inline in `blobs.data` so
reads remain a single query and pre-chunking `.fp` files keep working
unchanged. The chunk size is a compile-time constant
(`BRANCHFS_CHUNK_SIZE` in `ext/branchfs.h`, `CHUNK_SIZE` in
`fileserver/src/store.rs`) and must match across both writer
implementations. `branchctl gc` cleans orphaned rows in both `blobs`
*and* `blob_chunks` in one transaction.

**Inline GC on branch delete.** `branchctl delete <branch>` collects the
set of blob hashes referenced only by the deleted branch's `files` and
`fs_commits` rows and reclaims the orphaned ones in the same transaction
that drops the branch. Cost is bounded by the deleted branch's blob set,
not the whole store, so delete stays O(deleted branch) instead of
O(full blob table). Both `blobs` and `blob_chunks` rows are dropped;
shared blobs (still referenced by a sibling or parent branch) are left
in place. A stdout line `branchfs: reclaimed N orphaned blob(s), M
bytes` appears when N > 0.

### SF2a — Concurrent-writer resilience
Every PHP writer (branchctl, merge.php, checkpoint.php) opens SQLite with
`busyTimeout(15000)` (15 s), and the long-running critical transactions
in `merge.php` are wrapped in `sqlite_retry_busy()` — an
exponential-backoff retry helper (100 / 500 / 2000 ms, 3 retries) from
`scripts/sqlite_retry.php`. The Rust store does the same via
`Connection::busy_timeout(15s)` plus a `Store::with_busy_retry()`
wrapper, so the SFTP / SMB / MySQL write paths behave identically. Under
concurrent writer load (HTTP + SFTP + MySQL + parallel branchctl) a
transient `SQLITE_BUSY` no longer surfaces as a 500 / failed command.

### SF3 — WAL management
- SQLite is opened with `PRAGMA wal_autocheckpoint = 500` by every writer
  (`init_db.php`, `scripts/branchctl.php` sqlite_open, and
  `fileserver/src/store.rs` Store::open), tighter than the 1000-page
  default, so steady-state WAL growth stays below ~2 MB.
- `fileserver` runs an in-process periodic `PRAGMA wal_checkpoint(TRUNCATE)`
  on a 30-second cadence and one final checkpoint on Ctrl-C / SIGTERM, so
  the on-disk `.fp` is a complete snapshot when the process exits cleanly.
- `scripts/checkpoint.php <site.fp>` runs an explicit TRUNCATE checkpoint
  from the CLI for ops use (shrink after a batch import, verify before
  copying the `.fp` file).
- The `.fp` site file is the primary artifact. While the server is
  running, `.fp-wal` (write-ahead log) and `.fp-shm` (shared memory index)
  accompany it. A `cp site.fp backup.fp` while the server is up requires
  copying all three, or running `scripts/checkpoint.php` first to
  collapse the WAL into the main file.

### SF2 — Branch isolation
Both the filesystem and the database are branch-isolated.

- **Filesystem**: copy-on-write blobs per branch (existing `files` table overlay).
  Large-file writes use the chunked layout from SF1 so that uploading a
  multi-MB media asset never holds the entire payload in a single SQLite
  row — peak memory stays bounded by the chunk size instead of the file
  size.
- **Database**: each branch owns its own set of WordPress tables with prefix
  `b{branch_id}_wp_` (e.g. `b1_wp_posts`, `b2_wp_options`). Creating a branch
  copies the parent's tables into the new prefix.

---

## CLI — `forkpress`

### CLI1 — `forkpress init <site.fp>`
Create a new `.fp` file.

```
forkpress init my-blog.fp [--title "My Blog"] [--root-host localhost]
                          [--admin-password <pw>]
```

Creates schema, seeds `main` branch, inserts `site_config`. Also creates
the default `admin` user with role `admin`. Admin password source:
1. `--admin-password <pw>` CLI flag
2. `FORKPRESS_ADMIN_PASSWORD` env var
3. random 24-char password printed **once** to stdout. There is no way
   to recover this password after the init run — rotate it with
   `forkpress user remove admin && forkpress user add admin <newpw> --role admin`.

### CLI2 — `forkpress start <site.fp>`
Boot the full stack from a `.fp` file.

```
forkpress start my-blog.fp
  [--host 127.0.0.1]  [--port 18080]
  [--sftp-port 2222]  [--smb-port 445]
  [--mysql-port 3306]
  [--workers N]            # PHP HTTP worker count (default: min(8, num_cpus*2))
  [--gc-interval <dur>]    # background branchctl gc cadence; off by default
  [--dolt-bind 0.0.0.0]    # deprecated no-op, kept for compat
  [--no-fileserver]
  [--logs ./my-blog.logs]
```

Starts: PHP HTTP server, SFTP server, SMB2 server, MySQL proxy.

Startup banner must print connection strings for all active services and
the active worker count (`PHP workers: N (PHP_CLI_SERVER_WORKERS)`).

`--workers N` controls how many PHP processes the built-in server forks
to handle concurrent HTTP requests. Default is
`min(8, num_cpus::get() * 2)` — capped at 8 because WordPress request
serving is bounded by SQLite write contention long before CPU. Pass
`--workers 1` to force the legacy single-request loop (useful when
attaching a debugger to the PHP process); `PHP_CLI_SERVER_WORKERS` is
left unset in that mode so behaviour is byte-identical to the
pre-multi-worker baseline. The flag is honoured on Linux and macOS;
Windows is not a target (see Non-requirements).

`--gc-interval <dur>` enables a background thread that runs
`branchctl gc` on a recurring cadence while the server is up. The
value is a single-unit duration — one of `<N>s`, `<N>m`, or `<N>h`
(e.g. `--gc-interval 300s`, `--gc-interval 10m`, `--gc-interval 1h`).
Compound forms like `1h30m` are not supported. Omitting the flag (or
passing `0` / an invalid value) leaves background GC disabled; inline
GC on branch delete runs either way. Each tick logs stdout/stderr to
`<work-dir>/logs/gc.log` so the primary PHP and fileserver logs stay
unpolluted.

### CLI3 — `forkpress branch <site.fp> <subcommand>`

| Subcommand | Description |
|------------|-------------|
| `list` | List all branches |
| `create <name> [--from <parent>]` | Create branch (COW filesystem + copy DB tables) |
| `commit <name> [-m "msg"]` | Snapshot current state |
| `log <name> [-n <count>]` | Show commit history |
| `show <name>` | Show branch info |
| `diff <a> <b>` | File diff between two branches |
| `merge <from> --into <target>` | 3-way file + DB merge |
| `reset <name> <hash>` | Reset branch to commit |
| `rollback <name>` | Reset to previous commit |
| `delete <name>` | Delete branch + its DB tables + ancestor snapshot rows. Runs an inline GC step that reclaims blobs uniquely owned by the deleted branch — no waiting for a manual `gc` pass. |
| `gc` | Remove unreferenced blobs across the whole store (shares `fs_gc()` with inline delete-GC). |

### CLI4 — `forkpress backup <source.fp> <dest.fp>`
Consistent hot-copy of a running site via SQLite's `VACUUM INTO`.
Takes a brief read lock, emits a defragmented / checkpointed copy,
releases. Safe to run while the server is up.

### CLI5 — `forkpress export <source.fp> <output-dir>`
Writes the site to a portable directory tree:
```
<output-dir>/
  manifest.json                     (format_version, branch topology, site_config)
  branches/<name>/files/…           (resolved file tree per branch)
  branches/<name>/db.sql            (SQL dump of b{id}_wp_* tables + indexes)
```
Suitable for long-term archival and format-version migrations.

### CLI7 — `forkpress user <subcommand>`
Manage authentication users. Forwards to `scripts/user_admin.php`.

| Subcommand | Description |
|------------|-------------|
| `add <user> <pw> [--role admin\|write\|read]` | Insert or update a user. Default role is `write`. |
| `list` | Print username / role / created_at for every user. |
| `remove <user>` | Delete a user row. Deleting the last admin locks out the site until the flag `auth_enabled='0'` is set out-of-band. |
| `verify <user> <pw>` | Exit 0 if the password matches the stored bcrypt hash, 1 otherwise. Used by tests and recovery scripts. |
| `auth-enabled [0\|1]` | Read or write the `site_config.auth_enabled` flag. With no arg, prints the current value. |

Password hashing: bcrypt via PHP's `password_hash(PASSWORD_BCRYPT)`;
also stores `mysql_sha1 = SHA1(SHA1(password))` hex so the MySQL proxy
can run a real `mysql_native_password` handshake without ever holding
the plaintext or a reversible hash.

### CLI6 — `forkpress import <input-dir> <new.fp>`
Reverse of export: runs `init`, recreates branches in topological
order, replays their files through the branchfs stream wrapper, then
applies each branch's `db.sql`. `b{old_id}_wp_` prefixes are rewritten
to the re-assigned `b{new_id}_wp_` on the fly.

---

## Feature requirements

### F1 — Multi-branch HTTP serving
- Main site: `http://localhost:<port>/`
- Branch site: `http://<branch>.localhost:<port>/`
- Branch selected by: subdomain > `X-Branch` header > `wp_branch` signed cookie > `?_branch=` query param > `main`
- **Worker concurrency**: PHP's built-in server is launched with
  `PHP_CLI_SERVER_WORKERS=N` so concurrent HTTP requests are handled
  by N independent PHP processes instead of serialised through a
  single accept loop. N is the resolved value of `forkpress start
  --workers` (default `min(8, num_cpus*2)`). Each worker is a separate
  process and opens its own SQLite handle on the `.fp` file; WAL mode
  plus the SF2a busy-retry helpers keep the multi-writer path safe.
  The PHP `branchfs` builtin extension is process-local, so workers
  do not share OPcache bytecode or wrapper state — every worker
  re-hydrates branchfs on the first request it serves.

### F2 — Git access
- `git clone http://host:port/site.git` — exports current branch's file tree
- `git push` — imports files into branch, creates `fs_commits` row
- One git branch = one forkpress branch
- Implemented by `scripts/git_server/server.php` + WordPress php-toolkit
- **Authentication** (see FAUTH): push (`git-receive-pack`) requires
  HTTP Basic credentials verified against the `users` table when
  `site_config.auth_enabled='1'`. Users with role `read` get HTTP 403;
  anyone else gets HTTP 401 with `WWW-Authenticate: Basic realm="BranchFS Git"`.

### F3 — SFTP access
- SFTP server on `:2222` (configurable)
- Path scheme: `/branch-name/path/to/file`
- **Authentication** (see FAUTH): `auth_enabled='1'` sites require a
  username+password that verifies against `users.password_hash` via
  bcrypt. `auth_none` is rejected. Users with role `read` can browse
  and read files but any write / mkdir / remove returns
  `SSH_FX_PERMISSION_DENIED`.
- File close after write → `fs_commits` row with message `sftp: edit <path>`

### F4 — SMB2 access
- SMB2 server on `:445` (configurable)
- Share per branch: `\\host\branch-name\`
- **Authentication** (see FAUTH): the current SMB2 implementation does
  not speak NTLMSSP, so per-user credential verification on the wire
  is not implemented. When `auth_enabled='1'`, the server accepts SMB2
  NEGOTIATE (so clients can detect the service) but rejects every
  subsequent command (SESSION_SETUP, TREE_CONNECT, CREATE, …) with
  `STATUS_ACCESS_DENIED`. Sites that need SMB must either keep
  `auth_enabled='0'` or use SFTP / the MySQL proxy instead.
- File close after write → `fs_commits` row

### F5 — MySQL access
- MySQL-compatible protocol server on `:3306` (configurable)
- Connect: `mysql -u root -h localhost -P 3306 <branch-name>`
- **Authentication** (see FAUTH): implements the MySQL
  `mysql_native_password` handshake against `users.mysql_sha1` (stored
  as `SHA1(SHA1(password))` hex) when `auth_enabled='1'`. Empty
  passwords are rejected. Users with role `read` can run
  SELECT/SHOW/EXPLAIN/PRAGMA/SET but any INSERT/UPDATE/DELETE/DDL
  returns `ER_ACCESS_DENIED_ERROR`.
- The branch-name is the MySQL "database" in the connection string
- All `b{branch_id}_wp_*` tables for that branch are visible as `wp_*`
  (table name translation: strip the `b{id}_` prefix in responses)
- SELECT, INSERT, UPDATE, DELETE supported
- Writes go directly to SQLite (WAL-safe, no buffering)
- **Identifier rewriting is tokenizer-aware** (`rewrite_wp_prefix` in
  `fileserver/src/mysql_proxy.rs`): `wp_<table>` is rewritten to
  `b{id}_wp_<table>` only when the occurrence is an identifier. String
  literals (`'…'`, `"…"`, including `''` and `\\'` escapes) and SQL
  comments (`-- …`, `/* … */`) are left byte-for-byte unchanged, so role
  slugs, capability keys, and meta-key values (`'wp_capabilities'`,
  `'wp_user_roles'`, `'wp_user_level'`) round-trip intact.

### F6 — Branching with DB isolation
- `branchctl create my-branch [--from main]` copies:
  - Filesystem: new entry in `branches` table (COW, no file copy needed)
  - Database: recreates every `b{parent_id}_wp_X` table under `b{new_id}_wp_X`
    using the original DDL from `sqlite_master` so all constraints (PRIMARY KEY,
    UNIQUE, NOT NULL, indexes) are preserved, then `INSERT INTO … SELECT *`
  - Ancestor snapshot: every copied row is stored in `db_snapshots(branch_id,
    table_name, row_pk, row_json)` to enable 3-way DB merge later
- All branches see their own isolated database state
- `router.php` sets `$GLOBALS['_branchfs_table_prefix'] = "b{id}_wp_"` before
  WordPress boots so HTTP requests use the correct branch's tables

### F7 — Committing
- `branchctl commit <branch>` records a snapshot in `fs_commits` + `fs_commit_files`
- `commit_hash` auto-generated (SQLite `DEFAULT (lower(hex(randomblob(16))))`)

### F8 — Rollback / Reset
- `branchctl rollback <branch>` restores files from the previous `fs_commit_files` snapshot
- `branchctl reset <branch> <hash>` restores files from a specific commit

### F9a — OPcache invalidation for out-of-process writers
Router serves PHP via `branchfs://<branch>/path.php` URLs so OPcache keys
bytecode per-branch. Any writer that mutates a `.php` file on disk from a
*different* PHP process (branchctl merge/reset/rollback) must communicate
the change to the running PHP server, otherwise stale bytecode keeps
serving the old code until OPcache TTL expires.

Implementation (`scripts/opcache.php`):
- Table `opcache_invalidations(id, url, created_at)` is a cross-process
  queue inside the `.fp` file.
- Writers (merge.php, branchctl reset/rollback) call
  `opcache_queue_invalidate($db, $branch, $path)` inside the same
  transaction as the file change. Non-`.php`/`.phtml` paths are skipped
  because they never have OPcache entries.
- Router (`e2e/router.php`) calls `opcache_process_pending($db)` at the
  start of every request, pops every queued URL, and calls
  `opcache_invalidate()` for each. Safe when OPcache is not loaded — the
  queue is still drained, just without the actual invalidation call.

### FAUTH — Authentication for write surfaces
Every non-HTTP write surface (SFTP, SMB, MySQL proxy, git push) honours
a per-site auth gate backed by two new SQLite tables:

- `users(username TEXT PRIMARY KEY, password_hash TEXT, mysql_sha1 TEXT,
  role TEXT CHECK(role IN ('admin','write','read')), created_at TEXT)`
- `site_config(key TEXT PRIMARY KEY, value TEXT)` — auth is on when
  `auth_enabled='1'`.

Role semantics:

| Role | HTTP preview | SFTP read | SFTP write | MySQL SELECT | MySQL DML | SMB | git clone | git push |
|------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `admin`/`write` | open | yes | yes | yes | yes | if `auth_enabled='0'` | yes | yes |
| `read` | open | yes | **403** | yes | **403** | if `auth_enabled='0'` | yes | **403** |
| unauthenticated (when `auth_enabled='1'`) | open | **reject** | — | **reject** | — | **reject** | open | **401** |

Back-compat:
- **new sites** created via `forkpress init` / `scripts/init_db.php`
  default to `auth_enabled='1'` and ship with an `admin` user whose
  one-time password is printed during init.
- **pre-change sites** (the `.fp` was created before this feature) have
  no `site_config` row at all; the first `fs_migrate()` on them seeds
  `auth_enabled='0'` so existing workflows don't break. Operators opt
  into auth by running `forkpress user add admin <pw> --role admin &&
  forkpress user auth-enabled 1`.

Password recovery is intentionally manual — there is no email loop, no
reset token. Operators rotate credentials with
`forkpress user remove <name> && forkpress user add <name> <newpw>`.

### F9 — Merge
`branchctl merge <from> --into <target> [--strategy=abort|ours|theirs]`

**Phase 1 — File 3-way merge** (existing)
Uses the fork-time `fs_commits` snapshot as the common ancestor. Per-path:
- Source changed, target unchanged → apply source
- Target changed, source unchanged → keep target
- Both changed identically → no-op
- Both changed differently → conflict (honour `--strategy`)

**Phase 2 — DB 3-way merge** (implemented)
Uses `db_snapshots` rows recorded at `branchctl create` time as the common
ancestor. Per row (keyed by primary key JSON):

| Ancestor | Source now | Target now | Result |
|----------|------------|------------|--------|
| absent | present | absent | INSERT into target |
| absent | present | present, same | no-op |
| absent | present | present, different | CONFLICT |
| present | absent | unchanged | DELETE from target |
| present | absent | changed | CONFLICT |
| present | changed | unchanged | UPDATE target |
| present | unchanged | changed | keep target |
| present | changed same | changed same | no-op |
| present | changed A | changed B | CONFLICT |

New tables on source (e.g. new plugin) are created on target with DDL-preserving
`CREATE TABLE` + `INSERT`. All changes are applied atomically in one transaction.
`--strategy=abort` leaves the target completely unchanged on any conflict.

**Ancestor snapshot refresh (iterative merges)**
After a successful merge (i.e. the DB ops transaction committed — strategies
`theirs` and `ours`, or `abort` with zero conflicts), the source branch's
`db_snapshots` rows are replaced in the same transaction with a fresh
snapshot of the source branch's current tables. This keeps iterative
"merge → tweak → merge" workflows clean: without the refresh, rows the
first merge propagated to target would show up on the second merge as
"both sides changed vs (stale) ancestor" and trigger spurious conflicts
on every previously-merged row. Merges that exit via `--strategy=abort` on
a real conflict do not touch `db_snapshots`.

---

## Non-requirements (explicit out of scope for v1)

- (Removed — username+password+role auth implemented for SFTP/SMB/MySQL/git; see FAUTH)
- OAuth / SSO / TOTP / passwordless authentication
- TLS / HTTPS
- Multi-user concurrent editing with conflict resolution
- Windows binary target — implies `PHP_CLI_SERVER_WORKERS` is unavailable;
  multi-worker HTTP serving is therefore Linux/macOS only
- (Removed — single-process PHP serving replaced by multi-worker mode in F1 / CLI2)
- (Removed — now implemented via ancestor snapshot refresh in F9)
- DB merge for schema changes (column add/drop across branches)

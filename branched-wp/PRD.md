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
| `blobs`, `branches`, `files`, `fs_commits`, `fs_commit_files` | WordPress filesystem (COW) |
| `b{id}_wp_*` tables | WordPress database, one set of tables per branch |
| `site_config` | Title, root host, PHP ini extras |

WAL mode guarantees every committed write is durable even on crash — no
pack/unpack cycle, no temp state.

### SF2 — Branch isolation
Both the filesystem and the database are branch-isolated.

- **Filesystem**: copy-on-write blobs per branch (existing `files` table overlay).
- **Database**: each branch owns its own set of WordPress tables with prefix
  `b{branch_id}_wp_` (e.g. `b1_wp_posts`, `b2_wp_options`). Creating a branch
  copies the parent's tables into the new prefix.

---

## CLI — `forkpress`

### CLI1 — `forkpress init <site.fp>`
Create a new `.fp` file.

```
forkpress init my-blog.fp [--title "My Blog"] [--root-host localhost]
```

Creates schema, seeds `main` branch, inserts `site_config`.

### CLI2 — `forkpress start <site.fp>`
Boot the full stack from a `.fp` file.

```
forkpress start my-blog.fp
  [--host 127.0.0.1]  [--port 18080]
  [--sftp-port 2222]  [--smb-port 445]
  [--mysql-port 3306]
  [--dolt-bind 0.0.0.0]   # deprecated no-op, kept for compat
  [--no-fileserver]
  [--logs ./my-blog.logs]
```

Starts: PHP HTTP server, SFTP server, SMB2 server, MySQL proxy.

Startup banner must print connection strings for all active services.

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
| `delete <name>` | Delete branch + its DB tables |
| `gc` | Remove unreferenced blobs |

---

## Feature requirements

### F1 — Multi-branch HTTP serving
- Main site: `http://localhost:<port>/`
- Branch site: `http://<branch>.localhost:<port>/`
- Branch selected by: subdomain > `X-Branch` header > `wp_branch` signed cookie > `?_branch=` query param > `main`

### F2 — Git access
- `git clone http://host:port/site.git` — exports current branch's file tree
- `git push` — imports files into branch, creates `fs_commits` row
- One git branch = one forkpress branch
- Implemented by `scripts/git_server/server.php` + WordPress php-toolkit

### F3 — SFTP access
- SFTP server on `:2222` (configurable)
- Path scheme: `/branch-name/path/to/file`
- Any username/password accepted (prototype auth)
- File close after write → `fs_commits` row with message `sftp: edit <path>`

### F4 — SMB2 access
- SMB2 server on `:445` (configurable)
- Share per branch: `\\host\branch-name\`
- File close after write → `fs_commits` row

### F5 — MySQL access
- MySQL-compatible protocol server on `:3306` (configurable)
- Connect: `mysql -u root -h localhost -P 3306 <branch-name>`
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

---

## Non-requirements (explicit out of scope for v1)

- Authentication / access control beyond prototype guest auth
- TLS / HTTPS
- Multi-user concurrent editing with conflict resolution
- Windows binary target
- Merge of already-merged branches (re-merge with updated ancestor)
- DB merge for schema changes (column add/drop across branches)

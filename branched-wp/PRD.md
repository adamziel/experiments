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
| `merge <from> --into <target>` | 3-way file merge |
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

### F6 — Branching with DB isolation
- `branchctl create my-branch [--from main]` copies:
  - Filesystem: new entry in `branches` table (COW, no file copy needed)
  - Database: `CREATE TABLE b{new_id}_wp_X AS SELECT * FROM b{parent_id}_wp_X`
    for every WordPress table in the parent branch
- All branches see their own isolated database state

### F7 — Committing
- `branchctl commit <branch>` records a snapshot in `fs_commits` + `fs_commit_files`
- `commit_hash` auto-generated (SQLite `DEFAULT (lower(hex(randomblob(16))))`)

### F8 — Rollback / Reset
- `branchctl rollback <branch>` restores files from the previous `fs_commit_files` snapshot
- `branchctl reset <branch> <hash>` restores files from a specific commit

### F9 — Merge
- `branchctl merge <from> --into <target>` performs 3-way file merge
- DB merge: not yet implemented (noted as limitation)

---

## Non-requirements (explicit out of scope for v1)

- Database-level 3-way merge
- Authentication / access control beyond prototype guest auth
- TLS / HTTPS
- Multi-user concurrent editing with conflict resolution
- Windows binary target

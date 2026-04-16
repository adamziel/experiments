# BranchFS

Branch-aware WordPress: full copy-on-write versioning of both files (via branchfs overlay) and database rows (via Dolt).

## Git Smart-HTTP Surface

Developers can clone, pull, and push the entire WordPress state (files + DB) via standard Git.

### Endpoints

```
GET  /<site>.git/info/refs?service=git-upload-pack    # clone/fetch discovery
POST /<site>.git/git-upload-pack                      # serve clone/fetch
GET  /<site>.git/info/refs?service=git-receive-pack   # push discovery
POST /<site>.git/git-receive-pack                     # accept push (HTTP basic auth)
```

### Usage

```bash
# Clone the full site — file tree + a single SQLite DB file the clone can
# boot against directly.
git clone http://wp.localhost:18080/site.git wp-clone

# Boot the clone locally — edits to the admin UI write to the SQLite
# file via the SQLite Database Integration plugin.
cd wp-clone/wordpress
php -S 127.0.0.1:9080 -t .

# Push changes back (requires auth). Edits made via the local admin,
# via wp-cli, or directly to the .ht.sqlite file all round-trip the
# same way.
git push http://admin:admin@wp.localhost:18080/site.git main

# Push to a new branch
git push http://admin:admin@wp.localhost:18080/site.git main:marketing
```

### Repository Layout

```
site.git/
  wordpress/                             # WP file tree from branchfs overlay
    wp-admin/
    wp-content/
      db.php                             # SQLite integration drop-in (required)
      database/
        .ht.sqlite                       # ONE SQLite file with every wp_* table
      plugins/
        sqlite-database-integration/     # vendored SQLite integration plugin
      themes/
      ...
    wp-includes/
    index.php
  db-meta.json                           # {dolt_commit_hash, branch, exported_at, schema_version}
```

A cloned tree is a **self-contained, bootable WordPress install**. Run
`php -S` against `wp-clone/wordpress/` and WordPress will boot against
the SQLite file via the `wp-content/db.php` drop-in. Edits to the SQLite
file — whether raw (via PHP's `SQLite3`), via `wp-cli`, or through the
admin UI — round-trip to the remote on `git push`.

### How the round-trip works

**Clone/Pull (server → client):**

1. The server reads every `wp_*` table out of Dolt.
2. It translates each MySQL `CREATE TABLE` to SQLite-compatible DDL by
   feeding it through `WP_SQLite_Driver` (the same translator the
   SQLite integration plugin uses at runtime). This guarantees the
   clone's SQLite schema matches what the plugin expects.
3. It bulk-inserts every row into a fresh SQLite file at
   `wp-content/database/.ht.sqlite`.
4. It drops `db.php` (the integration plugin's `db.copy` template,
   filled in) at `wp-content/db.php`, vendors the plugin tree at
   `wp-content/plugins/sqlite-database-integration/`, and writes
   `db-meta.json` at the repo root.
5. The SQLite-file bytes are cached per `(branch, dolt_hash)` so the
   two HTTP roundtrips of a single `git clone` see byte-identical
   content — necessary because SQLite's own file format embeds
   non-deterministic page-layout bytes that would otherwise drift
   between `info/refs` and `upload-pack`.

**Push (client → server):**

1. The client's tree must include the canonical `.ht.sqlite`. It's the
   source of truth for DB state; omitting it is a push error.
2. The server writes the pushed `.ht.sqlite` to a temp file and opens
   it with PHP's native `SQLite3` class — **never shells out** to the
   `sqlite3` binary.
3. For each `wp_*` table: reads rows from SQLite, compares to the same
   table on the Dolt branch, emits `INSERT`, `UPDATE`, and `DELETE`
   statements to reconcile. Schema drift (added or removed columns)
   generates `ALTER TABLE` first; ambiguous diffs (added AND removed)
   are rejected.
4. File changes to `wordpress/` apply to the branchfs overlay as
   before. Paths that the server owns — `wp-content/db.php`,
   `wp-content/database/`, `wp-content/plugins/sqlite-database-integration/`,
   `db-meta.json` — are **ignored** on push; they are regenerated
   from scratch on every clone, so echoing them through would just
   bloat the overlay.
5. A paired `fs_commit` + `DOLT_COMMIT` is recorded. On any push
   failure the server resets both git and Dolt to the pre-push state
   and returns HTTP 500.

### Transient filter on `wp_options`

Rows in `wp_options` whose `option_name` starts with `_transient_`,
`_site_transient_`, or `_transient_timeout_` are **omitted** from the
exported SQLite file. Transients are ephemeral WP cache; exporting
them would churn the repo on every clone. On push, transients that
only exist on the Dolt side (not in the pushed SQLite file) are
preserved — they're not spurious deletions.

### Auth

Push requires HTTP basic auth. Read access is anonymous.

- Dev default: `admin` / `admin`.
- Override via environment:
  - `BRANCHFS_GIT_USER=<username>`
  - `BRANCHFS_GIT_PASSWORD_HASH=<php password_hash() output>`
    (generate with `php -r "echo password_hash('your-pass', PASSWORD_DEFAULT);"`)
- Set `BRANCHFS_PROD=1` to force production-mode auth: the server will
  refuse all pushes unless both `BRANCHFS_GIT_USER` and
  `BRANCHFS_GIT_PASSWORD_HASH` are provided and the default `admin/admin`
  is no longer accepted.

### Reserved branch names

Push refuses to create a branch named `www`, `admin`, `api`, `mail`,
`localhost`, or `wp` — those labels collide with router host parsing and
would leave a subdomain pointing at the wrong place.

## php-toolkit

The Git protocol implementation uses [WordPress/php-toolkit](https://github.com/wordpress/php-toolkit). The library is vendored at `vendor/wordpress-php-toolkit/`.

### Upstream Fixes

The following bugs were found and fixed in the vendored copy:

1. **Root commit handling in `GitEndpoint::handle_fetch_request`**: `isset()` check on `$parsed_commit->parents` always returns true since the property is initialized to `[]`. Fixed to use `empty()`.

2. **`find_objects_added_in` null parent**: `get_first_parent_hash()` returns null for root commits (empty parents array). Fixed to check `empty()` first.

3. **`get_commits_range` with NULL_HASH ancestor**: The function throws when the ancestor is NULL_HASH (root commit case). Added special handling for NULL_HASH to walk the full commit history.

4. **`handle_fetch_request` calling wrong method**: The endpoint called `find_objects_added_in()` with a common parent hash as the second argument, but that method's second parameter is `$options`. Fixed to call `find_objects_added_since()` instead.

## Development

```bash
# Build the extension
make

# Start dev server
bash e2e/dev.sh

# Run unit tests
make test

# Run git protocol e2e tests (requires dev server)
bash e2e/test_git_protocol.sh
```

### A note on `wp-debug.log` output

`wp-config.php` defines `WP_HTTP_BLOCK_EXTERNAL` to keep WordPress from
reaching out to wordpress.org, api.wordpress.com, Gravatar, etc. during
development. As a consequence, `wp-debug.log` will contain noisy warnings
like "could not establish secure connection to WordPress.org" — those are
**expected** in the dev stack and don't indicate a problem with branchfs.

### php-toolkit upstream PR

The vendored Git component fixes are also proposed upstream at
[wordpress/php-toolkit#235](https://github.com/wordpress/php-toolkit/pull/235)
("Fix Git component root-commit and full-history handling").

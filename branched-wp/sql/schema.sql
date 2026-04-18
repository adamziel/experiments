-- BranchFS SQLite Schema
-- Content-addressed blob store with per-branch copy-on-write file overlays

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS blobs (
    hash        TEXT PRIMARY KEY,
    data        BLOB NOT NULL,
    size        INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS branches (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT UNIQUE NOT NULL,
    parent_branch   TEXT,
    created_at      TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (parent_branch) REFERENCES branches(name)
);

CREATE TABLE IF NOT EXISTS files (
    branch_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,           -- NULL = tombstone (deleted on this branch)
    mode        INTEGER DEFAULT 33188,  -- 0100644 in octal
    mtime       INTEGER DEFAULT (strftime('%s','now')),
    is_dir      INTEGER DEFAULT 0,
    PRIMARY KEY (branch_id, path),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);

CREATE INDEX IF NOT EXISTS idx_files_path ON files(path);
CREATE INDEX IF NOT EXISTS idx_files_branch_dir ON files(branch_id, path);

-- File-side commit graph. Each fs_commits row captures a full file-tree
-- snapshot at the moment a branchctl commit happened.
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    commit_hash TEXT NOT NULL DEFAULT (lower(hex(randomblob(16)))),
    parent_id   INTEGER,                        -- previous fs_commit on this branch
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    UNIQUE (branch_id, commit_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_hash ON fs_commits(branch_id, commit_hash);

-- Full snapshot per commit. Blobs are content-addressed so only the
-- metadata rows duplicate between commits; real file content is shared.
CREATE TABLE IF NOT EXISTS fs_commit_files (
    commit_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,                           -- NULL = tombstone
    mode        INTEGER,
    mtime       INTEGER,
    is_dir      INTEGER DEFAULT 0,
    PRIMARY KEY (commit_id, path),
    FOREIGN KEY (commit_id) REFERENCES fs_commits(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);

-- Seed the 'main' branch
INSERT OR IGNORE INTO branches (name, parent_branch) VALUES ('main', NULL);

-- Authentication: per-site user accounts and site-wide config flags.
-- Every write surface (SFTP, SMB, MySQL proxy, git push) consults these
-- tables; see fileserver/src/store.rs verify_user_password / auth_enabled.
CREATE TABLE IF NOT EXISTS users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    mysql_sha1    TEXT,            -- SHA1(SHA1(password)) hex; required for MySQL native auth
    role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
    created_at    TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS site_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Brand-new site: auth is enabled by default. Existing sites created
-- before this change get auth_enabled='0' in branchctl.php::fs_migrate().
INSERT OR IGNORE INTO site_config (key, value) VALUES ('auth_enabled', '1');

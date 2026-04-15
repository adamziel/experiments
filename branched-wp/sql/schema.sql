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

-- File-side commit graph paired with Dolt commits.
-- Each fs_commits row captures a full file-tree snapshot at the moment a
-- branchctl commit happened; the paired Dolt commit hash lets `reset` and
-- `rollback` restore file state alongside the DB rewind.
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    dolt_hash   TEXT NOT NULL,
    parent_id   INTEGER,                        -- previous fs_commit on this branch
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    UNIQUE (branch_id, dolt_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_dolt ON fs_commits(branch_id, dolt_hash);

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

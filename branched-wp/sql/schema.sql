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

-- Seed the 'main' branch
INSERT OR IGNORE INTO branches (name, parent_branch) VALUES ('main', NULL);

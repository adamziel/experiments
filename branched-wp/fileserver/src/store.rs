use anyhow::{Context, Result, anyhow};
use rusqlite::{Connection, params};
use sha1::{Digest, Sha1};
use std::path::Path;
use std::sync::{Arc, Mutex};

pub struct Store {
    conn: Arc<Mutex<Connection>>,
}

#[derive(Debug, Clone)]
pub struct FileEntry {
    pub path: String,
    pub blob_hash: Option<String>,
    pub mode: i64,
    pub mtime: i64,
    pub is_dir: bool,
    pub size: i64,
}

impl Store {
    pub fn open(db_path: &Path) -> Result<Self> {
        let conn = Connection::open(db_path)
            .with_context(|| format!("opening db {:?}", db_path))?;
        conn.execute_batch("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;")?;
        Ok(Self { conn: Arc::new(Mutex::new(conn)) })
    }

    pub fn branch_id(&self, branch_name: &str) -> Result<i64> {
        let conn = self.conn.lock().unwrap();
        conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )
        .with_context(|| format!("branch not found: {}", branch_name))
    }

    /// Walk branch parent chain to resolve the full file tree (COW semantics).
    pub fn list_files(&self, branch_name: &str) -> Result<Vec<FileEntry>> {
        let conn = self.conn.lock().unwrap();
        // Collect branch ancestry
        let mut branch_chain: Vec<i64> = Vec::new();
        let mut current = branch_name.to_string();
        loop {
            let row: Option<(i64, Option<String>)> = conn
                .query_row(
                    "SELECT id, parent_branch FROM branches WHERE name = ?1",
                    params![current],
                    |r| Ok((r.get(0)?, r.get(1)?)),
                )
                .ok();
            match row {
                Some((id, parent)) => {
                    branch_chain.push(id);
                    match parent {
                        Some(p) => current = p,
                        None => break,
                    }
                }
                None => break,
            }
        }

        // Paths seen (most-derived branch wins); tombstones excluded at end.
        let mut seen: std::collections::HashMap<String, FileEntry> =
            std::collections::HashMap::new();

        for branch_id in &branch_chain {
            let mut stmt = conn.prepare(
                "SELECT f.path, f.blob_hash, f.mode, f.mtime, f.is_dir, COALESCE(b.size, 0)
                 FROM files f
                 LEFT JOIN blobs b ON b.hash = f.blob_hash
                 WHERE f.branch_id = ?1",
            )?;
            let rows = stmt.query_map(params![branch_id], |r| {
                Ok(FileEntry {
                    path: r.get(0)?,
                    blob_hash: r.get(1)?,
                    mode: r.get(2)?,
                    mtime: r.get(3)?,
                    is_dir: r.get::<_, i64>(4)? != 0,
                    size: r.get(5)?,
                })
            })?;
            for row in rows {
                let entry = row?;
                seen.entry(entry.path.clone()).or_insert(entry);
            }
        }

        Ok(seen
            .into_values()
            .filter(|e| e.blob_hash.is_some() || e.is_dir)
            .collect())
    }

    pub fn read_file(&self, branch_name: &str, path: &str) -> Result<Vec<u8>> {
        let conn = self.conn.lock().unwrap();
        // Walk ancestry for this specific path.
        let mut current = branch_name.to_string();
        loop {
            let row: Option<(Option<String>, Option<String>)> = conn
                .query_row(
                    "SELECT f.blob_hash, b.parent_branch
                     FROM branches b
                     LEFT JOIN files f ON f.branch_id = b.id AND f.path = ?2
                     WHERE b.name = ?1",
                    params![current, path],
                    |r| Ok((r.get(0)?, r.get(1)?)),
                )
                .ok();
            match row {
                Some((Some(hash), _)) => {
                    // found
                    let data: Vec<u8> = conn.query_row(
                        "SELECT data FROM blobs WHERE hash = ?1",
                        params![hash],
                        |r| r.get(0),
                    )?;
                    return Ok(data);
                }
                Some((None, Some(parent))) => {
                    // Not on this branch, check parent
                    current = parent;
                }
                _ => return Err(anyhow!("file not found: {}", path)),
            }
        }
    }

    /// Write file content, create blob + update files row + create fs_commit.
    pub fn write_file(
        &self,
        branch_name: &str,
        path: &str,
        data: &[u8],
        protocol: &str,
    ) -> Result<()> {
        let hash = sha1_hex(data);
        let size = data.len() as i64;
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs() as i64;

        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )
        .with_context(|| format!("branch not found: {}", branch_name))?;

        conn.execute(
            "INSERT OR IGNORE INTO blobs(hash, data, size) VALUES(?1, ?2, ?3)",
            params![hash, data, size],
        )?;

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, ?3, 33188, ?4, 0)
             ON CONFLICT(branch_id, path) DO UPDATE SET
               blob_hash = excluded.blob_hash,
               mtime = excluded.mtime,
               mode = excluded.mode,
               is_dir = 0",
            params![branch_id, path, hash, now],
        )?;

        // Ensure parent directories exist
        ensure_parent_dirs(&conn, branch_id, path, now)?;

        // Create fs_commit
        let message = format!("{}: edit {}", protocol, path);
        record_snapshot(&conn, branch_id, &message)?;

        Ok(())
    }

    pub fn delete_file(&self, branch_name: &str, path: &str) -> Result<()> {
        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )?;

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 33188, strftime('%s','now'), 0)
             ON CONFLICT(branch_id, path) DO UPDATE SET blob_hash = NULL",
            params![branch_id, path],
        )?;

        record_snapshot(&conn, branch_id, &format!("delete {}", path))?;
        Ok(())
    }

    pub fn create_dir(&self, branch_name: &str, path: &str) -> Result<()> {
        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )?;
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs() as i64;

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 16877, ?3, 1)
             ON CONFLICT(branch_id, path) DO UPDATE SET is_dir=1, mode=16877",
            params![branch_id, path, now],
        )?;
        Ok(())
    }
}

fn sha1_hex(data: &[u8]) -> String {
    let mut h = Sha1::new();
    h.update(data);
    hex::encode(h.finalize())
}

fn ensure_parent_dirs(
    conn: &Connection,
    branch_id: i64,
    path: &str,
    now: i64,
) -> Result<()> {
    let mut p = path;
    while let Some(pos) = p.rfind('/') {
        p = &p[..pos];
        if p.is_empty() {
            break;
        }
        conn.execute(
            "INSERT OR IGNORE INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 16877, ?3, 1)",
            params![branch_id, p, now],
        )?;
    }
    Ok(())
}

fn record_snapshot(conn: &Connection, branch_id: i64, message: &str) -> Result<()> {
    // Resolve full tree for this branch (walk ancestry)
    let tree = resolve_tree(conn, branch_id)?;

    let parent_id: Option<i64> = conn
        .query_row(
            "SELECT id FROM fs_commits WHERE branch_id=?1 ORDER BY id DESC LIMIT 1",
            params![branch_id],
            |r| r.get(0),
        )
        .ok();

    conn.execute(
        "INSERT INTO fs_commits(branch_id, dolt_hash, parent_id, message)
         VALUES(?1, lower(hex(randomblob(8))), ?2, ?3)",
        params![branch_id, parent_id, message],
    )?;
    let commit_id = conn.last_insert_rowid();

    let mut stmt = conn.prepare(
        "INSERT INTO fs_commit_files(commit_id, path, blob_hash, mode, mtime, is_dir)
         VALUES(?1, ?2, ?3, ?4, ?5, ?6)",
    )?;
    for entry in &tree {
        stmt.execute(params![
            commit_id,
            entry.path,
            entry.blob_hash,
            entry.mode,
            entry.mtime,
            entry.is_dir as i64,
        ])?;
    }
    Ok(())
}

fn resolve_tree(conn: &Connection, branch_id: i64) -> Result<Vec<FileEntry>> {
    // Walk ancestry
    let mut chain: Vec<i64> = Vec::new();
    let mut current_id = branch_id;
    loop {
        chain.push(current_id);
        let parent: Option<i64> = conn
            .query_row(
                "SELECT b2.id FROM branches b1
                 JOIN branches b2 ON b2.name = b1.parent_branch
                 WHERE b1.id = ?1",
                params![current_id],
                |r| r.get(0),
            )
            .ok();
        match parent {
            Some(p) => current_id = p,
            None => break,
        }
    }

    let mut seen: std::collections::HashMap<String, FileEntry> = std::collections::HashMap::new();
    for bid in &chain {
        let mut stmt = conn.prepare(
            "SELECT f.path, f.blob_hash, f.mode, f.mtime, f.is_dir, COALESCE(b.size,0)
             FROM files f LEFT JOIN blobs b ON b.hash=f.blob_hash WHERE f.branch_id=?1",
        )?;
        let rows = stmt.query_map(params![bid], |r| {
            Ok(FileEntry {
                path: r.get(0)?,
                blob_hash: r.get(1)?,
                mode: r.get(2)?,
                mtime: r.get(3)?,
                is_dir: r.get::<_, i64>(4)? != 0,
                size: r.get(5)?,
            })
        })?;
        for row in rows {
            let e = row?;
            seen.entry(e.path.clone()).or_insert(e);
        }
    }
    Ok(seen.into_values().collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use rusqlite::Connection;
    use std::sync::{Arc, Mutex};
    use tempfile::NamedTempFile;

    fn make_store() -> Store {
        let f = NamedTempFile::new().unwrap();
        let path = f.path().to_path_buf();
        // Keep file alive by leaking the tempfile handle
        std::mem::forget(f);
        let conn = Connection::open(&path).unwrap();
        conn.execute_batch(include_str!("../../sql/schema.sql")).unwrap();
        Store { conn: Arc::new(Mutex::new(conn)) }
    }

    #[test]
    fn test_write_and_read() {
        let store = make_store();
        store.write_file("main", "hello.txt", b"hello world", "sftp").unwrap();
        let data = store.read_file("main", "hello.txt").unwrap();
        assert_eq!(data, b"hello world");
    }

    #[test]
    fn test_commit_created() {
        let store = make_store();
        store.write_file("main", "test.txt", b"data", "sftp").unwrap();
        let conn = store.conn.lock().unwrap();
        let count: i64 = conn
            .query_row("SELECT COUNT(*) FROM fs_commits", [], |r| r.get(0))
            .unwrap();
        assert!(count > 0);
        let msg: String = conn
            .query_row(
                "SELECT message FROM fs_commits ORDER BY id DESC LIMIT 1",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert!(msg.contains("test.txt"), "message was: {}", msg);
    }

    #[test]
    fn test_cow_inheritance() {
        let store = make_store();
        // Write file on main
        store.write_file("main", "shared.txt", b"parent content", "sftp").unwrap();
        // Create child branch
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO branches(name, parent_branch) VALUES('child', 'main')",
                [],
            )
            .unwrap();
        }
        // Child should see parent's file
        let data = store.read_file("child", "shared.txt").unwrap();
        assert_eq!(data, b"parent content");
    }

    #[test]
    fn test_tombstone() {
        let store = make_store();
        store.write_file("main", "gone.txt", b"bye", "sftp").unwrap();
        store.delete_file("main", "gone.txt").unwrap();
        let files = store.list_files("main").unwrap();
        let found = files.iter().any(|f| f.path == "gone.txt");
        assert!(!found, "tombstoned file should not appear in listing");
    }
}

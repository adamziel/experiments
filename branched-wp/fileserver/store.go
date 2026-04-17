package main

import (
	"crypto/rand"
	"crypto/sha1"
	"database/sql"
	"fmt"
	"os"
	"strings"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

// querier is satisfied by both *sql.DB and *sql.Tx.
type querier interface {
	QueryRow(query string, args ...any) *sql.Row
	Query(query string, args ...any) (*sql.Rows, error)
	Exec(query string, args ...any) (sql.Result, error)
}

// Store wraps the SQLite database.
type Store struct {
	db *sql.DB
}

// FileEntry represents a row from the flat files table.
type FileEntry struct {
	Path     string
	BlobHash string // empty string = directory or tombstone
	Mode     int64
	Mtime    int64
	IsDir    bool
}

// OpenStore opens (or creates) the SQLite store.
func OpenStore(path string) (*Store, error) {
	dsn := fmt.Sprintf("file:%s?_journal_mode=WAL&_foreign_keys=on&_busy_timeout=5000", path)
	db, err := sql.Open("sqlite3", dsn)
	if err != nil {
		return nil, err
	}
	// SQLite WAL allows concurrent reads but serialises writes; one conn is safest.
	db.SetMaxOpenConns(1)
	if err := db.Ping(); err != nil {
		return nil, fmt.Errorf("ping: %w", err)
	}
	return &Store{db: db}, nil
}

func (s *Store) Close() error { return s.db.Close() }

// getBranchID returns the integer primary key for a branch name.
func getBranchID(q querier, branch string) (int64, error) {
	var id int64
	err := q.QueryRow("SELECT id FROM branches WHERE name = ?", branch).Scan(&id)
	if err == sql.ErrNoRows {
		return 0, fmt.Errorf("%w: branch %q not found", os.ErrNotExist, branch)
	}
	return id, err
}

// resolveTree walks the branch ancestry and returns the merged file tree.
// Files in child branches shadow parent files (COW overlay).
func resolveTree(q querier, branchID int64) (map[string]FileEntry, error) {
	tree := make(map[string]FileEntry)
	tombstoned := make(map[string]bool)

	bid := branchID
	for bid > 0 {
		rows, err := q.Query(
			"SELECT path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = ?", bid)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var path string
			var blobHash sql.NullString
			var mode, mtime int64
			var isDir int
			if err := rows.Scan(&path, &blobHash, &mode, &mtime, &isDir); err != nil {
				rows.Close()
				return nil, err
			}
			if _, seen := tree[path]; seen {
				continue
			}
			if tombstoned[path] {
				continue
			}
			// NULL blob_hash on a non-directory = tombstone
			if !blobHash.Valid && isDir == 0 {
				tombstoned[path] = true
				continue
			}
			tree[path] = FileEntry{
				Path:     path,
				BlobHash: blobHash.String,
				Mode:     mode,
				Mtime:    mtime,
				IsDir:    isDir != 0,
			}
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			return nil, err
		}

		// Walk to parent branch
		var parentID sql.NullInt64
		err = q.QueryRow(
			`SELECT b2.id FROM branches b1
			 JOIN branches b2 ON b1.parent_branch = b2.name
			 WHERE b1.id = ?`, bid).Scan(&parentID)
		if err == sql.ErrNoRows || !parentID.Valid {
			break
		}
		if err != nil {
			return nil, err
		}
		bid = parentID.Int64
	}
	return tree, nil
}

// filterDir returns the direct children of dirPath from a flat file map.
func filterDir(tree map[string]FileEntry, dirPath string) []FileEntry {
	dirPath = strings.TrimRight(dirPath, "/")
	seen := make(map[string]bool)
	var result []FileEntry

	for path, entry := range tree {
		var rest string
		if dirPath == "" {
			rest = path
		} else {
			prefix := dirPath + "/"
			if !strings.HasPrefix(path, prefix) {
				continue
			}
			rest = path[len(prefix):]
		}
		parts := strings.SplitN(rest, "/", 2)
		name := parts[0]
		if name == "" || seen[name] {
			continue
		}
		seen[name] = true
		if len(parts) == 1 {
			result = append(result, entry)
		} else {
			// Implicit directory synthesised from path prefix
			fullDir := name
			if dirPath != "" {
				fullDir = dirPath + "/" + name
			}
			result = append(result, FileEntry{
				Path:  fullDir,
				IsDir: true,
				Mode:  0755,
				Mtime: time.Now().Unix(),
			})
		}
	}
	return result
}

// ListBranches returns all branch names.
func (s *Store) ListBranches() ([]string, error) {
	rows, err := s.db.Query("SELECT name FROM branches ORDER BY name")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var names []string
	for rows.Next() {
		var n string
		if err := rows.Scan(&n); err != nil {
			return nil, err
		}
		names = append(names, n)
	}
	return names, rows.Err()
}

// ListDir returns the direct children of dirPath in a branch.
func (s *Store) ListDir(branch, dirPath string) ([]FileEntry, error) {
	branchID, err := getBranchID(s.db, branch)
	if err != nil {
		return nil, err
	}
	tree, err := resolveTree(s.db, branchID)
	if err != nil {
		return nil, err
	}
	return filterDir(tree, dirPath), nil
}

// StatFile returns FileInfo for path inside branch.
func (s *Store) StatFile(branch, path string) (os.FileInfo, error) {
	branchID, err := getBranchID(s.db, branch)
	if err != nil {
		return nil, err
	}
	tree, err := resolveTree(s.db, branchID)
	if err != nil {
		return nil, err
	}

	if entry, ok := tree[path]; ok {
		mode := os.FileMode(entry.Mode)
		if entry.IsDir {
			mode |= os.ModeDir
		}
		var size int64
		if !entry.IsDir && entry.BlobHash != "" {
			s.db.QueryRow("SELECT size FROM blobs WHERE hash = ?", entry.BlobHash).Scan(&size)
		}
		return &syntheticFileInfo{
			name:  lastName(path),
			size:  size,
			mode:  mode,
			mtime: time.Unix(entry.Mtime, 0),
		}, nil
	}

	// Check if path is an implicit directory (prefix of existing paths)
	prefix := path + "/"
	for p := range tree {
		if strings.HasPrefix(p, prefix) {
			return &syntheticFileInfo{
				name:  lastName(path),
				mode:  0755 | os.ModeDir,
				mtime: time.Now(),
			}, nil
		}
	}

	return nil, os.ErrNotExist
}

// ReadFile returns the raw bytes and FileInfo for a file in a branch.
func (s *Store) ReadFile(branch, path string) ([]byte, os.FileInfo, error) {
	branchID, err := getBranchID(s.db, branch)
	if err != nil {
		return nil, nil, err
	}
	tree, err := resolveTree(s.db, branchID)
	if err != nil {
		return nil, nil, err
	}
	entry, ok := tree[path]
	if !ok {
		return nil, nil, os.ErrNotExist
	}
	if entry.IsDir {
		fi := &syntheticFileInfo{
			name:  lastName(path),
			mode:  os.FileMode(entry.Mode) | os.ModeDir,
			mtime: time.Unix(entry.Mtime, 0),
		}
		return nil, fi, nil
	}
	var data []byte
	err = s.db.QueryRow("SELECT data FROM blobs WHERE hash = ?", entry.BlobHash).Scan(&data)
	if err != nil {
		return nil, nil, err
	}
	fi := &syntheticFileInfo{
		name:  lastName(path),
		size:  int64(len(data)),
		mode:  os.FileMode(entry.Mode),
		mtime: time.Unix(entry.Mtime, 0),
	}
	return data, fi, nil
}

// WriteFile stores a file blob, upserts the files row, and records a commit.
func (s *Store) WriteFile(branch, path string, data []byte, message string) error {
	sum := sha1.Sum(data)
	hash := fmt.Sprintf("%x", sum)
	mtime := time.Now().Unix()

	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	branchID, err := getBranchID(tx, branch)
	if err != nil {
		return err
	}

	if _, err = tx.Exec(
		"INSERT OR IGNORE INTO blobs (hash, data, size) VALUES (?, ?, ?)",
		hash, data, len(data)); err != nil {
		return fmt.Errorf("insert blob: %w", err)
	}

	if _, err = tx.Exec(
		`INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir)
		 VALUES (?, ?, ?, 33188, ?, 0)
		 ON CONFLICT(branch_id, path) DO UPDATE SET
		   blob_hash = excluded.blob_hash,
		   mtime     = excluded.mtime,
		   is_dir    = 0`,
		branchID, path, hash, mtime); err != nil {
		return fmt.Errorf("upsert file: %w", err)
	}

	if err := recordSnapshot(tx, branchID, message); err != nil {
		return err
	}
	return tx.Commit()
}

// DeleteFile tombstones a file and records a commit.
func (s *Store) DeleteFile(branch, path string, message string) error {
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	branchID, err := getBranchID(tx, branch)
	if err != nil {
		return err
	}

	if _, err = tx.Exec(
		`INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir)
		 VALUES (?, ?, NULL, 0, ?, 0)
		 ON CONFLICT(branch_id, path) DO UPDATE SET
		   blob_hash = NULL,
		   mtime     = excluded.mtime`,
		branchID, path, time.Now().Unix()); err != nil {
		return fmt.Errorf("tombstone: %w", err)
	}

	if err := recordSnapshot(tx, branchID, message); err != nil {
		return err
	}
	return tx.Commit()
}

// RenameFile copies old → new within the same branch, then tombstones old.
func (s *Store) RenameFile(branch, oldPath, newPath string) error {
	data, _, err := s.ReadFile(branch, oldPath)
	if err != nil {
		return err
	}
	msg := fmt.Sprintf("fileserver: rename %s → %s", oldPath, newPath)
	if err := s.WriteFile(branch, newPath, data, msg); err != nil {
		return err
	}
	return s.DeleteFile(branch, oldPath, msg)
}

// MkdirAll inserts a directory entry for path.
func (s *Store) MkdirAll(branch, path string) error {
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	branchID, err := getBranchID(tx, branch)
	if err != nil {
		return err
	}
	if _, err = tx.Exec(
		`INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir)
		 VALUES (?, ?, NULL, 16877, ?, 1)
		 ON CONFLICT(branch_id, path) DO NOTHING`,
		branchID, path, time.Now().Unix()); err != nil {
		return err
	}
	return tx.Commit()
}

// recordSnapshot inserts an fs_commits row plus the full tree snapshot.
// The dolt_hash is a synthetic random identifier since these commits have
// no corresponding Dolt transaction.
func recordSnapshot(tx *sql.Tx, branchID int64, message string) error {
	b := make([]byte, 20)
	rand.Read(b)
	doltHash := fmt.Sprintf("fileserver-%x", b)

	var parentID sql.NullInt64
	tx.QueryRow(
		"SELECT id FROM fs_commits WHERE branch_id = ? ORDER BY id DESC LIMIT 1",
		branchID).Scan(&parentID)

	res, err := tx.Exec(
		`INSERT INTO fs_commits (branch_id, dolt_hash, parent_id, message)
		 VALUES (?, ?, ?, ?)`,
		branchID, doltHash, nullableInt64(parentID), message)
	if err != nil {
		return fmt.Errorf("insert fs_commit: %w", err)
	}
	commitID, err := res.LastInsertId()
	if err != nil {
		return fmt.Errorf("last insert id: %w", err)
	}

	// Snapshot the full resolved tree at this commit.
	tree, err := resolveTree(tx, branchID)
	if err != nil {
		return err
	}
	for _, entry := range tree {
		var blobArg any
		if entry.BlobHash != "" {
			blobArg = entry.BlobHash
		}
		if _, err = tx.Exec(
			`INSERT INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir)
			 VALUES (?, ?, ?, ?, ?, ?)`,
			commitID, entry.Path, blobArg, entry.Mode, entry.Mtime, boolToInt(entry.IsDir)); err != nil {
			return fmt.Errorf("insert fs_commit_files: %w", err)
		}
	}
	return nil
}

// entriesToFileInfos converts FileEntry slice to []os.FileInfo, fetching sizes from blobs.
func (s *Store) entriesToFileInfos(entries []FileEntry) ([]os.FileInfo, error) {
	infos := make([]os.FileInfo, 0, len(entries))
	for _, e := range entries {
		mode := os.FileMode(e.Mode)
		if e.IsDir {
			mode |= os.ModeDir
		}
		var size int64
		if !e.IsDir && e.BlobHash != "" {
			s.db.QueryRow("SELECT size FROM blobs WHERE hash = ?", e.BlobHash).Scan(&size)
		}
		infos = append(infos, &syntheticFileInfo{
			name:  lastName(e.Path),
			size:  size,
			mode:  mode,
			mtime: time.Unix(e.Mtime, 0),
		})
	}
	return infos, nil
}

// --- helpers ---

func nullableInt64(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

func lastName(path string) string {
	path = strings.TrimRight(path, "/")
	if idx := strings.LastIndex(path, "/"); idx >= 0 {
		return path[idx+1:]
	}
	return path
}

// syntheticFileInfo implements os.FileInfo for in-memory entries.
type syntheticFileInfo struct {
	name  string
	size  int64
	mode  os.FileMode
	mtime time.Time
}

func (fi *syntheticFileInfo) Name() string       { return fi.name }
func (fi *syntheticFileInfo) Size() int64        { return fi.size }
func (fi *syntheticFileInfo) Mode() os.FileMode  { return fi.mode }
func (fi *syntheticFileInfo) ModTime() time.Time { return fi.mtime }
func (fi *syntheticFileInfo) IsDir() bool        { return fi.mode&os.ModeDir != 0 }
func (fi *syntheticFileInfo) Sys() any           { return nil }

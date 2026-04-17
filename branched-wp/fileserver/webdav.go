package main

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"time"

	"golang.org/x/net/webdav"
)

func startWebDAVServer(addr string, store *Store) error {
	fs := &webdavFS{store: store}
	handler := &webdav.Handler{
		FileSystem: fs,
		LockSystem: webdav.NewMemLS(),
		Logger: func(r *http.Request, err error) {
			if err != nil {
				log.Printf("WebDAV %s %s: %v", r.Method, r.URL.Path, err)
			}
		},
	}

	srv := &http.Server{Addr: addr, Handler: handler}
	log.Printf("WebDAV server listening on %s", addr)
	log.Printf("  macOS: Connect to Server → http://host%s/branch-name/", addr)
	log.Printf("  Windows: Map Network Drive → http://host%s/branch-name/", addr)

	go func() {
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("WebDAV server: %v", err)
		}
	}()
	return nil
}

// webdavFS implements webdav.FileSystem backed by the SQLite store.
type webdavFS struct {
	store *Store
}

// splitWebDAVPath splits a WebDAV path into (branch, filePath).
//   "/" → ("", "")
//   "/branch" → ("branch", "")
//   "/branch/wp-content/foo.php" → ("branch", "wp-content/foo.php")
func splitWebDAVPath(name string) (branch, path string) {
	cleaned := strings.Trim(name, "/")
	if cleaned == "" {
		return "", ""
	}
	parts := strings.SplitN(cleaned, "/", 2)
	branch = parts[0]
	if len(parts) > 1 {
		path = parts[1]
	}
	return branch, path
}

func (fs *webdavFS) Mkdir(ctx context.Context, name string, perm os.FileMode) error {
	branch, path := splitWebDAVPath(name)
	if branch == "" {
		return fmt.Errorf("cannot create directory at root")
	}
	if path == "" {
		return fmt.Errorf("cannot create branch via WebDAV")
	}
	return fs.store.MkdirAll(branch, path)
}

func (fs *webdavFS) OpenFile(ctx context.Context, name string, flag int, perm os.FileMode) (webdav.File, error) {
	branch, path := splitWebDAVPath(name)

	// Root: list all branches
	if branch == "" {
		names, err := fs.store.ListBranches()
		if err != nil {
			return nil, err
		}
		infos := make([]os.FileInfo, 0, len(names))
		for _, n := range names {
			infos = append(infos, &syntheticFileInfo{n, 0, 0755 | os.ModeDir, time.Now()})
		}
		fi := &syntheticFileInfo{"/", 0, 0755 | os.ModeDir, time.Now()}
		return newDavDir(fi, infos), nil
	}

	// Branch root directory
	if path == "" {
		if _, err := getBranchID(fs.store.db, branch); err != nil {
			return nil, os.ErrNotExist
		}
		entries, err := fs.store.ListDir(branch, "")
		if err != nil {
			return nil, err
		}
		infos, err := fs.store.entriesToFileInfos(entries)
		if err != nil {
			return nil, err
		}
		fi := &syntheticFileInfo{branch, 0, 0755 | os.ModeDir, time.Now()}
		return newDavDir(fi, infos), nil
	}

	// Write / create
	if flag&(os.O_WRONLY|os.O_RDWR|os.O_CREATE) != 0 {
		var initData []byte
		if flag&os.O_TRUNC == 0 {
			// Not truncating: seed buffer with existing content (append / in-place).
			existing, _, err := fs.store.ReadFile(branch, path)
			if err == nil {
				initData = existing
			}
		}
		fi := &syntheticFileInfo{
			name:  lastName(path),
			mode:  perm &^ os.ModeDir,
			mtime: time.Now(),
		}
		return &davFile{
			store:  fs.store,
			branch: branch,
			path:   path,
			fi:     fi,
			write:  true,
			buf:    bytes.NewBuffer(initData),
		}, nil
	}

	// Read: stat first to determine file vs directory
	fi, err := fs.store.StatFile(branch, path)
	if err != nil {
		return nil, err
	}
	if fi.IsDir() {
		entries, err := fs.store.ListDir(branch, path)
		if err != nil {
			return nil, err
		}
		infos, err := fs.store.entriesToFileInfos(entries)
		if err != nil {
			return nil, err
		}
		return newDavDir(fi, infos), nil
	}

	data, _, err := fs.store.ReadFile(branch, path)
	if err != nil {
		return nil, err
	}
	return &davFile{
		fi:     fi,
		reader: bytes.NewReader(data),
	}, nil
}

func (fs *webdavFS) RemoveAll(ctx context.Context, name string) error {
	branch, path := splitWebDAVPath(name)
	if branch == "" || path == "" {
		return fmt.Errorf("cannot remove branch or root via WebDAV")
	}
	return fs.store.DeleteFile(branch, path, fmt.Sprintf("webdav: delete %s", path))
}

func (fs *webdavFS) Rename(ctx context.Context, oldName, newName string) error {
	srcBranch, srcPath := splitWebDAVPath(oldName)
	dstBranch, dstPath := splitWebDAVPath(newName)
	if srcBranch != dstBranch {
		return fmt.Errorf("cannot rename across branches")
	}
	return fs.store.RenameFile(srcBranch, srcPath, dstPath)
}

func (fs *webdavFS) Stat(ctx context.Context, name string) (os.FileInfo, error) {
	branch, path := splitWebDAVPath(name)
	if branch == "" {
		return &syntheticFileInfo{"/", 0, 0755 | os.ModeDir, time.Now()}, nil
	}
	if path == "" {
		if _, err := getBranchID(fs.store.db, branch); err != nil {
			return nil, os.ErrNotExist
		}
		return &syntheticFileInfo{branch, 0, 0755 | os.ModeDir, time.Now()}, nil
	}
	return fs.store.StatFile(branch, path)
}

// --- davFile implements webdav.File for regular files ---

type davFile struct {
	store  *Store
	branch string
	path   string
	fi     os.FileInfo

	// reading
	reader *bytes.Reader

	// writing
	write bool
	buf   *bytes.Buffer
}

func (f *davFile) Close() error {
	if f.write && f.store != nil {
		return f.store.WriteFile(f.branch, f.path, f.buf.Bytes(),
			fmt.Sprintf("webdav: edit %s", f.path))
	}
	return nil
}

func (f *davFile) Read(p []byte) (int, error) {
	if f.reader == nil {
		return 0, io.EOF
	}
	return f.reader.Read(p)
}

func (f *davFile) Seek(offset int64, whence int) (int64, error) {
	if f.reader == nil {
		return 0, fmt.Errorf("not a readable file")
	}
	return f.reader.Seek(offset, whence)
}

func (f *davFile) Readdir(count int) ([]os.FileInfo, error) {
	return nil, fmt.Errorf("not a directory")
}

func (f *davFile) Stat() (os.FileInfo, error) { return f.fi, nil }

func (f *davFile) Write(p []byte) (int, error) {
	if !f.write {
		return 0, fmt.Errorf("file not opened for writing")
	}
	return f.buf.Write(p)
}

// --- davDir implements webdav.File for directory listings ---

type davDir struct {
	fi        os.FileInfo
	entries   []os.FileInfo
	dirOffset int
}

func newDavDir(fi os.FileInfo, entries []os.FileInfo) *davDir {
	return &davDir{fi: fi, entries: entries}
}

func (d *davDir) Close() error                                  { return nil }
func (d *davDir) Read(p []byte) (int, error)                    { return 0, io.EOF }
func (d *davDir) Seek(offset int64, whence int) (int64, error)  { return 0, fmt.Errorf("not a file") }
func (d *davDir) Stat() (os.FileInfo, error)                    { return d.fi, nil }
func (d *davDir) Write(p []byte) (int, error)                   { return 0, fmt.Errorf("not a file") }

func (d *davDir) Readdir(count int) ([]os.FileInfo, error) {
	if count <= 0 {
		all := d.entries[d.dirOffset:]
		d.dirOffset = len(d.entries)
		return all, nil
	}
	if d.dirOffset >= len(d.entries) {
		return nil, io.EOF
	}
	end := d.dirOffset + count
	if end > len(d.entries) {
		end = len(d.entries)
	}
	result := d.entries[d.dirOffset:end]
	d.dirOffset = end
	return result, nil
}

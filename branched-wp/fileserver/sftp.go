package main

import (
	"bytes"
	"crypto/rand"
	"crypto/rsa"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"sync"
	"time"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
)

func startSFTPServer(addr string, store *Store) error {
	config := &ssh.ServerConfig{
		// Accept any password for prototype use; production should use key auth.
		PasswordCallback: func(conn ssh.ConnMetadata, password []byte) (*ssh.Permissions, error) {
			return &ssh.Permissions{}, nil
		},
		NoClientAuth: true,
	}

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return fmt.Errorf("generate host key: %w", err)
	}
	signer, err := ssh.NewSignerFromKey(key)
	if err != nil {
		return fmt.Errorf("create signer: %w", err)
	}
	config.AddHostKey(signer)

	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return fmt.Errorf("listen %s: %w", addr, err)
	}
	log.Printf("SFTP server listening on %s (no-auth mode)", addr)

	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				log.Printf("SFTP accept: %v", err)
				return
			}
			go serveSFTPConn(conn, config, store)
		}
	}()
	return nil
}

func serveSFTPConn(conn net.Conn, config *ssh.ServerConfig, store *Store) {
	defer conn.Close()
	sshConn, chans, reqs, err := ssh.NewServerConn(conn, config)
	if err != nil {
		log.Printf("SSH handshake: %v", err)
		return
	}
	defer sshConn.Close()
	go ssh.DiscardRequests(reqs)

	for newChan := range chans {
		if newChan.ChannelType() != "session" {
			newChan.Reject(ssh.UnknownChannelType, "unsupported channel type")
			continue
		}
		ch, requests, err := newChan.Accept()
		if err != nil {
			log.Printf("accept channel: %v", err)
			return
		}
		go serveSFTPSession(ch, requests, store)
	}
}

func serveSFTPSession(ch ssh.Channel, reqs <-chan *ssh.Request, store *Store) {
	defer ch.Close()
	for req := range reqs {
		if req.Type == "subsystem" && len(req.Payload) >= 4 {
			subsys := string(req.Payload[4:])
			if subsys == "sftp" {
				req.Reply(true, nil)
				h := &sftpHandler{store: store}
				srv := sftp.NewRequestServer(ch, sftp.Handlers{
					FileGet:  h,
					FilePut:  h,
					FileCmd:  h,
					FileList: h,
				})
				if err := srv.Serve(); err != nil && err != io.EOF {
					log.Printf("SFTP serve: %v", err)
				}
				return
			}
		}
		if req.WantReply {
			req.Reply(false, nil)
		}
	}
}

// splitBranchPath splits "/branch/path/to/file" → ("branch", "path/to/file").
// Root "/" returns ("", "").
func splitBranchPath(filepath string) (branch, path string) {
	for len(filepath) > 0 && filepath[0] == '/' {
		filepath = filepath[1:]
	}
	if filepath == "" {
		return "", ""
	}
	idx := -1
	for i, c := range filepath {
		if c == '/' {
			idx = i
			break
		}
	}
	if idx < 0 {
		return filepath, ""
	}
	return filepath[:idx], filepath[idx+1:]
}

// sftpHandler implements sftp.ReadWriteAt, sftp.FileCmder, and sftp.Lister.
type sftpHandler struct {
	store *Store
}

// Fileread handles read requests — returns a bytes.Reader over the blob content.
func (h *sftpHandler) Fileread(r *sftp.Request) (io.ReaderAt, error) {
	branch, path := splitBranchPath(r.Filepath)
	if branch == "" || path == "" {
		return nil, fmt.Errorf("invalid path: %s", r.Filepath)
	}
	data, _, err := h.store.ReadFile(branch, path)
	if err != nil {
		return nil, err
	}
	return bytes.NewReader(data), nil
}

// Filewrite handles write requests — returns a buffer that commits on Close.
func (h *sftpHandler) Filewrite(r *sftp.Request) (io.WriterAt, error) {
	branch, path := splitBranchPath(r.Filepath)
	if branch == "" || path == "" {
		return nil, fmt.Errorf("invalid path: %s", r.Filepath)
	}
	return &sftpWriteBuffer{store: h.store, branch: branch, path: path}, nil
}

// Filecmd handles mkdir/remove/rename/setstat commands.
func (h *sftpHandler) Filecmd(r *sftp.Request) error {
	switch r.Method {
	case "Mkdir":
		branch, path := splitBranchPath(r.Filepath)
		if branch == "" || path == "" {
			return fmt.Errorf("invalid path: %s", r.Filepath)
		}
		return h.store.MkdirAll(branch, path)

	case "Remove", "Rmdir":
		branch, path := splitBranchPath(r.Filepath)
		if branch == "" || path == "" {
			return fmt.Errorf("invalid path: %s", r.Filepath)
		}
		return h.store.DeleteFile(branch, path, fmt.Sprintf("sftp: delete %s", path))

	case "Rename":
		srcBranch, srcPath := splitBranchPath(r.Filepath)
		dstBranch, dstPath := splitBranchPath(r.Target)
		if srcBranch != dstBranch {
			return fmt.Errorf("cannot rename across branches")
		}
		return h.store.RenameFile(srcBranch, srcPath, dstPath)

	case "Setstat":
		return nil // ignore attribute changes for prototype

	case "Link", "Symlink":
		return fmt.Errorf("symlinks not supported")

	default:
		return fmt.Errorf("unsupported command: %s", r.Method)
	}
}

// Filelist handles stat and directory listing requests.
func (h *sftpHandler) Filelist(r *sftp.Request) (sftp.ListerAt, error) {
	switch r.Method {
	case "List":
		branch, path := splitBranchPath(r.Filepath)
		if branch == "" {
			// Root: list branches as directories
			names, err := h.store.ListBranches()
			if err != nil {
				return nil, err
			}
			infos := make([]os.FileInfo, 0, len(names))
			for _, n := range names {
				infos = append(infos, &syntheticFileInfo{n, 0, 0755 | os.ModeDir, time.Now()})
			}
			return listerAt(infos), nil
		}
		entries, err := h.store.ListDir(branch, path)
		if err != nil {
			return nil, err
		}
		infos, err := h.store.entriesToFileInfos(entries)
		if err != nil {
			return nil, err
		}
		return listerAt(infos), nil

	case "Stat", "Lstat":
		branch, path := splitBranchPath(r.Filepath)
		if branch == "" {
			fi := &syntheticFileInfo{"/", 0, 0755 | os.ModeDir, time.Now()}
			return listerAt([]os.FileInfo{fi}), nil
		}
		if path == "" {
			// Branch directory
			if _, err := getBranchID(h.store.db, branch); err != nil {
				return nil, os.ErrNotExist
			}
			fi := &syntheticFileInfo{branch, 0, 0755 | os.ModeDir, time.Now()}
			return listerAt([]os.FileInfo{fi}), nil
		}
		fi, err := h.store.StatFile(branch, path)
		if err != nil {
			return nil, err
		}
		return listerAt([]os.FileInfo{fi}), nil

	case "Readlink":
		return nil, fmt.Errorf("symlinks not supported")
	}
	return nil, fmt.Errorf("unsupported Filelist method: %s", r.Method)
}

// listerAt implements sftp.ListerAt for a slice of os.FileInfo.
type listerAt []os.FileInfo

func (l listerAt) ListAt(ls []os.FileInfo, offset int64) (int, error) {
	if offset >= int64(len(l)) {
		return 0, io.EOF
	}
	n := copy(ls, l[offset:])
	if n < len(ls) {
		return n, io.EOF
	}
	return n, nil
}

// sftpWriteBuffer accumulates WriteAt calls and commits the file on Close.
// pkg/sftp calls Close() if the returned io.WriterAt also implements io.Closer.
type sftpWriteBuffer struct {
	store  *Store
	branch string
	path   string
	mu     sync.Mutex
	data   []byte
}

func (w *sftpWriteBuffer) WriteAt(p []byte, off int64) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	end := int(off) + len(p)
	if end > len(w.data) {
		grown := make([]byte, end)
		copy(grown, w.data)
		w.data = grown
	}
	copy(w.data[off:], p)
	return len(p), nil
}

func (w *sftpWriteBuffer) Close() error {
	w.mu.Lock()
	data := w.data
	w.mu.Unlock()
	msg := fmt.Sprintf("sftp: edit %s", w.path)
	return w.store.WriteFile(w.branch, w.path, data, msg)
}

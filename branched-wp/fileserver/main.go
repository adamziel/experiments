package main

import (
	"flag"
	"log"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	dbPath := flag.String("db", "./store.db", "path to SQLite database file")
	sftpAddr := flag.String("sftp-addr", ":2222", "SFTP server listen address")
	webdavAddr := flag.String("webdav-addr", ":8888", "WebDAV server listen address")
	flag.Parse()

	store, err := OpenStore(*dbPath)
	if err != nil {
		log.Fatalf("open store %s: %v", *dbPath, err)
	}
	defer store.Close()

	if err := startSFTPServer(*sftpAddr, store); err != nil {
		log.Fatalf("start SFTP server: %v", err)
	}

	if err := startWebDAVServer(*webdavAddr, store); err != nil {
		log.Fatalf("start WebDAV server: %v", err)
	}

	log.Printf("fileserver running — SFTP=%s  WebDAV=%s  DB=%s", *sftpAddr, *webdavAddr, *dbPath)

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)
	<-sig
	log.Println("shutting down")
}

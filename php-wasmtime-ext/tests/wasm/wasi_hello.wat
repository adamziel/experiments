(module
  ;; WASI fd_write: write to a file descriptor
  ;; Signature: (fd, iovs_ptr, iovs_len, nwritten_ptr) -> errno
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))

  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory (export "memory") 1)

  ;; Data: "Hello from WASI!\n"
  (data (i32.const 16) "Hello from WASI!\n")

  ;; _start function (WASI entry point)
  (func (export "_start")
    ;; iov.buf_ptr = 16 (pointer to string)
    (i32.store (i32.const 0) (i32.const 16))
    ;; iov.buf_len = 17 (length of string)
    (i32.store (i32.const 4) (i32.const 17))

    ;; fd_write(stdout=1, iovs=0, iovs_len=1, nwritten=8)
    (drop (call $fd_write
      (i32.const 1)   ;; fd = stdout
      (i32.const 0)   ;; iovs pointer
      (i32.const 1)   ;; iovs count
      (i32.const 8))) ;; nwritten pointer
  )
)

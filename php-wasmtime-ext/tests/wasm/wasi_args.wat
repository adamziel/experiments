(module
  ;; WASI functions for args and stdout
  (import "wasi_snapshot_preview1" "args_sizes_get"
    (func $args_sizes_get (param i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "args_get"
    (func $args_get (param i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "fd_write"
    (func $fd_write (param i32 i32 i32 i32) (result i32)))
  (import "wasi_snapshot_preview1" "proc_exit"
    (func $proc_exit (param i32)))

  (memory (export "memory") 2)

  ;; Layout:
  ;; 0-3: argc result
  ;; 4-7: argv_buf_size result
  ;; 8-11: nwritten result
  ;; 100-199: iov struct
  ;; 200-299: argv pointers
  ;; 1024+: argv buffer

  (func (export "_start")
    (local $argc i32)
    (local $i i32)
    (local $ptr i32)
    (local $len i32)

    ;; Get args sizes
    (drop (call $args_sizes_get (i32.const 0) (i32.const 4)))
    (local.set $argc (i32.load (i32.const 0)))

    ;; Get args into buffers
    (drop (call $args_get (i32.const 200) (i32.const 1024)))

    ;; Print each argument on its own line
    (local.set $i (i32.const 0))
    (block $break
      (loop $loop
        (br_if $break (i32.ge_u (local.get $i) (local.get $argc)))

        ;; Get pointer to this arg
        (local.set $ptr (i32.load (i32.add (i32.const 200) (i32.mul (local.get $i) (i32.const 4)))))

        ;; Find string length (scan for null byte)
        (local.set $len (i32.const 0))
        (block $found
          (loop $scan
            (br_if $found (i32.eqz (i32.load8_u (i32.add (local.get $ptr) (local.get $len)))))
            (local.set $len (i32.add (local.get $len) (i32.const 1)))
            (br $scan)
          )
        )

        ;; Write the arg string
        (i32.store (i32.const 100) (local.get $ptr))
        (i32.store (i32.const 104) (local.get $len))
        (drop (call $fd_write (i32.const 1) (i32.const 100) (i32.const 1) (i32.const 8)))

        ;; Write newline
        (i32.store8 (i32.const 150) (i32.const 10))
        (i32.store (i32.const 100) (i32.const 150))
        (i32.store (i32.const 104) (i32.const 1))
        (drop (call $fd_write (i32.const 1) (i32.const 100) (i32.const 1) (i32.const 8)))

        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $loop)
      )
    )
  )
)

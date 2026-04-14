(module
  ;; Import host functions
  (import "env" "host_add" (func $host_add (param i32 i32) (result i32)))
  (import "env" "host_multiply" (func $host_multiply (param i32 i32) (result i32)))
  (import "env" "host_log" (func $host_log (param i32)))
  (import "env" "host_get_value" (func $host_get_value (result i32)))
  (import "env" "host_double_f64" (func $host_double_f64 (param f64) (result f64)))

  (memory (export "memory") 1)

  ;; Call host_add from wasm
  (func (export "call_host_add") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    call $host_add)

  ;; Chain: call host_add then host_multiply
  (func (export "chain_ops") (param i32 i32 i32) (result i32)
    (call $host_multiply
      (call $host_add (local.get 0) (local.get 1))
      (local.get 2)))

  ;; Call host_log to test void-returning callbacks
  (func (export "do_logging") (param i32)
    local.get 0
    call $host_log)

  ;; Get a value from the host
  (func (export "get_host_value") (result i32)
    call $host_get_value)

  ;; Test f64 callback
  (func (export "double_value") (param f64) (result f64)
    local.get 0
    call $host_double_f64)

  ;; Recursive callback: wasm calls host, host can call wasm again
  (func (export "compute") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    call $host_add
    i32.const 10
    i32.add)
)

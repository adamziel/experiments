(module
  (memory (export "memory") 1 4)

  (func (export "store_i32") (param i32 i32)
    local.get 0
    local.get 1
    i32.store)

  (func (export "load_i32") (param i32) (result i32)
    local.get 0
    i32.load)

  (func (export "store_f64") (param i32 f64)
    local.get 0
    local.get 1
    f64.store)

  (func (export "load_f64") (param i32) (result f64)
    local.get 0
    f64.load)

  (func (export "store_byte") (param i32 i32)
    local.get 0
    local.get 1
    i32.store8)

  (func (export "load_byte") (param i32) (result i32)
    local.get 0
    i32.load8_u)

  (func (export "memory_size") (result i32)
    memory.size)

  (func (export "memory_grow") (param i32) (result i32)
    local.get 0
    memory.grow)

  ;; Write a string to memory at offset, return the offset
  (func (export "write_bytes") (param $offset i32) (param $byte i32) (param $count i32)
    (local $i i32)
    (block $break
      (loop $loop
        (br_if $break (i32.ge_u (local.get $i) (local.get $count)))
        (i32.store8
          (i32.add (local.get $offset) (local.get $i))
          (local.get $byte))
        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $loop)
      )
    )
  )
)

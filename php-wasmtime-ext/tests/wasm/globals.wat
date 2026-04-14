(module
  (global $counter (export "counter") (mut i32) (i32.const 0))
  (global $immutable (export "immutable") i32 (i32.const 42))
  (global $float_val (export "float_val") (mut f64) (f64.const 3.14))

  (func (export "get_counter") (result i32)
    global.get $counter)

  (func (export "increment") (result i32)
    global.get $counter
    i32.const 1
    i32.add
    global.set $counter
    global.get $counter)

  (func (export "set_counter") (param i32)
    local.get 0
    global.set $counter)

  (func (export "get_immutable") (result i32)
    global.get $immutable)

  (func (export "get_float") (result f64)
    global.get $float_val)

  (func (export "set_float") (param f64)
    local.get 0
    global.set $float_val)
)

(module
  (func (export "add_i32") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    i32.add)

  (func (export "sub_i32") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    i32.sub)

  (func (export "mul_i32") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    i32.mul)

  (func (export "div_i32") (param i32 i32) (result i32)
    local.get 0
    local.get 1
    i32.div_s)

  (func (export "add_i64") (param i64 i64) (result i64)
    local.get 0
    local.get 1
    i64.add)

  (func (export "add_f32") (param f32 f32) (result f32)
    local.get 0
    local.get 1
    f32.add)

  (func (export "add_f64") (param f64 f64) (result f64)
    local.get 0
    local.get 1
    f64.add)

  (func (export "negate_i32") (param i32) (result i32)
    i32.const 0
    local.get 0
    i32.sub)

  (func (export "abs_f64") (param f64) (result f64)
    local.get 0
    f64.abs)

  (func (export "no_return") (param i32))
)

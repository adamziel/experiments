(module
  (table (export "table") 3 funcref)

  (func $double (param i32) (result i32)
    local.get 0
    i32.const 2
    i32.mul)

  (func $triple (param i32) (result i32)
    local.get 0
    i32.const 3
    i32.mul)

  (func $square (param i32) (result i32)
    local.get 0
    local.get 0
    i32.mul)

  (elem (i32.const 0) $double $triple $square)

  (type $unary (func (param i32) (result i32)))

  ;; Call function by index from the table
  (func (export "call_indirect") (param $idx i32) (param $arg i32) (result i32)
    local.get $arg
    local.get $idx
    call_indirect (type $unary))
)

(module
  ;; Function returning multiple values
  (func (export "swap") (param i32 i32) (result i32 i32)
    local.get 1
    local.get 0)

  (func (export "divmod") (param i32 i32) (result i32 i32)
    (i32.div_s (local.get 0) (local.get 1))
    (i32.rem_s (local.get 0) (local.get 1)))

  (func (export "min_max") (param i32 i32) (result i32 i32)
    (if (result i32 i32) (i32.lt_s (local.get 0) (local.get 1))
      (then (local.get 0) (local.get 1))
      (else (local.get 1) (local.get 0))))
)

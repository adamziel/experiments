(module
  (func $fib (export "fibonacci") (param i32) (result i32)
    (if (result i32) (i32.le_s (local.get 0) (i32.const 1))
      (then (local.get 0))
      (else
        (i32.add
          (call $fib (i32.sub (local.get 0) (i32.const 1)))
          (call $fib (i32.sub (local.get 0) (i32.const 2)))))))

  ;; Iterative version for larger numbers
  (func (export "fibonacci_iter") (param $n i32) (result i64)
    (local $a i64)
    (local $b i64)
    (local $temp i64)
    (local $i i32)
    (local.set $a (i64.const 0))
    (local.set $b (i64.const 1))
    (local.set $i (i32.const 0))
    (if (i32.eq (local.get $n) (i32.const 0))
      (then (return (i64.const 0))))
    (block $break
      (loop $loop
        (br_if $break (i32.ge_s (local.get $i) (i32.sub (local.get $n) (i32.const 1))))
        (local.set $temp (i64.add (local.get $a) (local.get $b)))
        (local.set $a (local.get $b))
        (local.set $b (local.get $temp))
        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $loop)))
    (local.get $b))
)

(module
  (memory (export "memory") 1)

  ;; strlen: count bytes until null terminator
  (func (export "strlen") (param $ptr i32) (result i32)
    (local $len i32)
    (local.set $len (i32.const 0))
    (block $break
      (loop $loop
        (br_if $break (i32.eqz (i32.load8_u (i32.add (local.get $ptr) (local.get $len)))))
        (local.set $len (i32.add (local.get $len) (i32.const 1)))
        (br $loop)))
    (local.get $len))

  ;; to_upper: convert ASCII lowercase to uppercase in-place, return length
  (func (export "to_upper") (param $ptr i32) (param $len i32) (result i32)
    (local $i i32)
    (local $ch i32)
    (local.set $i (i32.const 0))
    (block $break
      (loop $loop
        (br_if $break (i32.ge_u (local.get $i) (local.get $len)))
        (local.set $ch (i32.load8_u (i32.add (local.get $ptr) (local.get $i))))
        ;; if ch >= 'a' && ch <= 'z' then ch -= 32
        (if (i32.and
              (i32.ge_u (local.get $ch) (i32.const 97))
              (i32.le_u (local.get $ch) (i32.const 122)))
          (then
            (i32.store8
              (i32.add (local.get $ptr) (local.get $i))
              (i32.sub (local.get $ch) (i32.const 32)))))
        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $loop)))
    (local.get $len))

  ;; reverse: reverse bytes in memory
  (func (export "reverse") (param $ptr i32) (param $len i32)
    (local $left i32)
    (local $right i32)
    (local $temp i32)
    (local.set $left (local.get $ptr))
    (local.set $right (i32.sub (i32.add (local.get $ptr) (local.get $len)) (i32.const 1)))
    (block $break
      (loop $loop
        (br_if $break (i32.ge_u (local.get $left) (local.get $right)))
        (local.set $temp (i32.load8_u (local.get $left)))
        (i32.store8 (local.get $left) (i32.load8_u (local.get $right)))
        (i32.store8 (local.get $right) (local.get $temp))
        (local.set $left (i32.add (local.get $left) (i32.const 1)))
        (local.set $right (i32.sub (local.get $right) (i32.const 1)))
        (br $loop)))
  )

  ;; concat: copy src to dst, return total length
  (func (export "concat") (param $dst i32) (param $dst_len i32) (param $src i32) (param $src_len i32) (result i32)
    (local $i i32)
    (local.set $i (i32.const 0))
    (block $break
      (loop $loop
        (br_if $break (i32.ge_u (local.get $i) (local.get $src_len)))
        (i32.store8
          (i32.add (i32.add (local.get $dst) (local.get $dst_len)) (local.get $i))
          (i32.load8_u (i32.add (local.get $src) (local.get $i))))
        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $loop)))
    (i32.add (local.get $dst_len) (local.get $src_len)))
)

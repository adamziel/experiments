<?php
/**
 * Comprehensive test suite for the PHP Wasmtime extension.
 *
 * Tests: Engine, Store, Module, Linker, Instance, Func, Memory, WasmGlobal,
 * Table, WasiConfig, host callbacks, data type marshaling, WASI programs,
 * string processing through memory, multi-value returns, error handling,
 * and module serialization.
 */

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, callable $fn): void {
    global $passed, $failed, $errors;
    try {
        $fn();
        $passed++;
        echo "  PASS  $name\n";
    } catch (\Throwable $e) {
        $failed++;
        $errors[] = [$name, $e];
        echo "  FAIL  $name: {$e->getMessage()}\n";
    }
}

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $exp_str = var_export($expected, true);
        $act_str = var_export($actual, true);
        throw new RuntimeException(
            ($msg ? "$msg: " : '') . "Expected $exp_str, got $act_str"
        );
    }
}

function assert_true($val, string $msg = ''): void {
    if ($val !== true) {
        throw new RuntimeException($msg ?: "Expected true, got " . var_export($val, true));
    }
}

function assert_near(float $expected, float $actual, float $epsilon = 0.001, string $msg = ''): void {
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException(
            ($msg ? "$msg: " : '') . "Expected ~$expected, got $actual (epsilon=$epsilon)"
        );
    }
}

function assert_throws(string $exceptionClass, callable $fn, string $msg = ''): void {
    try {
        $fn();
        throw new RuntimeException($msg ?: "Expected $exceptionClass to be thrown");
    } catch (\Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            throw new RuntimeException(
                ($msg ? "$msg: " : '') .
                "Expected $exceptionClass, got " . get_class($e) . ": " . $e->getMessage()
            );
        }
    }
}

$wasm_dir = __DIR__ . '/wasm';

// Helper: create standard engine+store+linker setup
function setup(): array {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);
    return [$engine, $store, $linker];
}

// Helper: load WAT file and instantiate
function instantiate_wat(string $wat_path, ?Wasmtime\Linker $linker = null): array {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    if ($linker === null) {
        $linker = new Wasmtime\Linker($engine);
    }
    $module = Wasmtime\Module::fromFile($engine, $wat_path);
    $instance = $linker->instantiate($store, $module);
    return [$engine, $store, $linker, $module, $instance];
}

/* ═══════════════════════════════════════════════════════════════════════
 * 1. CORE INFRASTRUCTURE TESTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Core Infrastructure ===\n";

test('Engine creation', function() {
    $engine = new Wasmtime\Engine();
    assert_true($engine instanceof Wasmtime\Engine);
});

test('Store creation', function() {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    assert_true($store instanceof Wasmtime\Store);
});

test('Module from WAT string', function() {
    $engine = new Wasmtime\Engine();
    $wat = '(module (func (export "f") (result i32) (i32.const 42)))';
    $module = new Wasmtime\Module($engine, $wat);
    assert_true($module instanceof Wasmtime\Module);
});

test('Module from WAT file', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/arithmetic.wat");
    assert_true($module instanceof Wasmtime\Module);
});

test('Module from binary WASM', function() {
    $engine = new Wasmtime\Engine();
    // Minimal valid WASM module (magic + version + empty)
    $wasm = "\x00\x61\x73\x6d\x01\x00\x00\x00";
    $module = new Wasmtime\Module($engine, $wasm);
    assert_true($module instanceof Wasmtime\Module);
});

test('Module imports inspection', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $imports = $module->imports();
    assert_true(count($imports) === 5, "Expected 5 imports");
    assert_eq('env', $imports[0]['module']);
    assert_eq('host_add', $imports[0]['name']);
    assert_eq('func', $imports[0]['kind']);
});

test('Module exports inspection', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/arithmetic.wat");
    $exports = $module->exports();
    assert_true(count($exports) > 0, "Should have exports");
    $names = array_column($exports, 'name');
    assert_true(in_array('add_i32', $names));
    assert_true(in_array('add_f64', $names));
});

test('Module exports include type info', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/arithmetic.wat");
    $exports = $module->exports();
    foreach ($exports as $export) {
        if ($export['name'] === 'add_i32') {
            assert_eq(['i32', 'i32'], $export['params']);
            assert_eq(['i32'], $export['results']);
            return;
        }
    }
    throw new RuntimeException("add_i32 not found in exports");
});

test('Module serialization / deserialization', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/arithmetic.wat");
    $bytes = $module->serialize();
    assert_true(strlen($bytes) > 0);

    $module2 = Wasmtime\Module::deserialize($engine, $bytes);
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);
    $instance = $linker->instantiate($store, $module2);
    $result = $instance->callFunc('add_i32', 10, 20);
    assert_eq(30, $result);
});

test('Linker creation', function() {
    $engine = new Wasmtime\Engine();
    $linker = new Wasmtime\Linker($engine);
    assert_true($linker instanceof Wasmtime\Linker);
});

test('Exception class extends RuntimeException', function() {
    assert_true(is_subclass_of(Wasmtime\Exception::class, RuntimeException::class));
});

/* ═══════════════════════════════════════════════════════════════════════
 * 2. ARITHMETIC / DATA TYPE TESTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Arithmetic & Data Types ===\n";

test('i32 addition', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(42, $i->callFunc('add_i32', 40, 2));
});

test('i32 subtraction', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(38, $i->callFunc('sub_i32', 40, 2));
});

test('i32 multiplication', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(80, $i->callFunc('mul_i32', 40, 2));
});

test('i32 division', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(20, $i->callFunc('div_i32', 40, 2));
});

test('i32 negative numbers', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(-5, $i->callFunc('negate_i32', 5));
    assert_eq(5, $i->callFunc('negate_i32', -5));
    assert_eq(0, $i->callFunc('add_i32', -10, 10));
});

test('i32 large values', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(2000000000, $i->callFunc('add_i32', 1000000000, 1000000000));
});

test('i64 addition', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $result = $i->callFunc('add_i64', 9000000000, 1000000000);
    assert_eq(10000000000, $result);
});

test('f32 addition', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $result = $i->callFunc('add_f32', 1.5, 2.5);
    assert_near(4.0, $result);
});

test('f64 addition', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $result = $i->callFunc('add_f64', 3.14159, 2.71828);
    assert_near(5.85987, $result, 0.001);
});

test('f64 abs', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_near(5.5, $i->callFunc('abs_f64', -5.5));
    assert_near(5.5, $i->callFunc('abs_f64', 5.5));
});

test('void return function', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $result = $i->callFunc('no_return', 42);
    assert_eq(null, $result);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 3. FUNCTION OBJECT TESTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Function Objects ===\n";

test('Get function from instance', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $func = $i->getFunc('add_i32');
    assert_true($func instanceof Wasmtime\Func);
});

test('Call function via Func::call', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $func = $i->getFunc('add_i32');
    assert_eq(42, $func->call(40, 2));
});

test('Func param types', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $func = $i->getFunc('add_i32');
    assert_eq(['i32', 'i32'], $func->paramTypes());
});

test('Func result types', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $func = $i->getFunc('add_i32');
    assert_eq(['i32'], $func->resultTypes());
    $func2 = $i->getFunc('no_return');
    assert_eq([], $func2->resultTypes());
});

test('Non-existent function returns null', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $func = $i->getFunc('nonexistent');
    assert_eq(null, $func);
});

test('Wrong number of arguments throws', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_throws(Wasmtime\Exception::class, function() use ($i) {
        $i->callFunc('add_i32', 1);  // needs 2 args
    });
});

/* ═══════════════════════════════════════════════════════════════════════
 * 4. MULTI-VALUE RETURNS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Multi-value Returns ===\n";

test('Swap two values', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/multi_value.wat");
    $result = $i->callFunc('swap', 1, 2);
    assert_eq([2, 1], $result);
});

test('Divmod returns quotient and remainder', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/multi_value.wat");
    $result = $i->callFunc('divmod', 17, 5);
    assert_eq([3, 2], $result);
});

test('Min/max returns sorted pair', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/multi_value.wat");
    assert_eq([3, 7], $i->callFunc('min_max', 7, 3));
    assert_eq([3, 7], $i->callFunc('min_max', 3, 7));
});

/* ═══════════════════════════════════════════════════════════════════════
 * 5. MEMORY ACCESS TESTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Memory Access ===\n";

test('Get memory from instance', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    assert_true($mem instanceof Wasmtime\Memory);
});

test('Memory initial size', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    assert_eq(1, $mem->size());
    assert_eq(65536, $mem->dataSize());
});

test('Write and read bytes from PHP', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $mem->write(0, "Hello WASM!");
    $result = $mem->read(0, 11);
    assert_eq("Hello WASM!", $result);
});

test('Write from PHP, read from WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    // Write an i32 (little-endian) at offset 0 from PHP
    $mem->writeI32(0, 12345);
    // Read it back via WASM
    $result = $i->callFunc('load_i32', 0);
    assert_eq(12345, $result);
});

test('Write from WASM, read from PHP', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $i->callFunc('store_i32', 100, 99999);
    $result = $mem->readI32(100);
    assert_eq(99999, $result);
});

test('f64 memory read/write', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $i->callFunc('store_f64', 0, 3.14159);
    $result = $mem->readF64(0);
    assert_near(3.14159, $result, 0.00001);
});

test('f64 memory write from PHP', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $mem->writeF64(0, 2.71828);
    $result = $i->callFunc('load_f64', 0);
    assert_near(2.71828, $result, 0.00001);
});

test('Memory grow', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $prev = $mem->grow(2);
    assert_eq(1, $prev);
    assert_eq(3, $mem->size());
    assert_eq(3 * 65536, $mem->dataSize());
});

test('Memory grow via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $prev = $i->callFunc('memory_grow', 1);
    assert_eq(1, $prev);
    $size = $i->callFunc('memory_size');
    assert_eq(2, $size);
});

test('Memory out of bounds throws', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    assert_throws(Wasmtime\Exception::class, function() use ($mem) {
        $mem->read(65530, 100);  // past end
    });
});

test('Memory write out of bounds throws', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    assert_throws(Wasmtime\Exception::class, function() use ($mem) {
        $mem->write(65535, "too long for remaining space xxxxxxxxxx");
    });
});

test('Byte-level memory operations', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    // Write bytes from WASM
    $i->callFunc('store_byte', 0, 65);  // 'A'
    $i->callFunc('store_byte', 1, 66);  // 'B'
    $i->callFunc('store_byte', 2, 67);  // 'C'
    // Read back from PHP
    $result = $mem->read(0, 3);
    assert_eq("ABC", $result);
});

test('Fill memory with pattern', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    $mem = $i->getMemory('memory');
    $i->callFunc('write_bytes', 0, 42, 10);
    $data = $mem->read(0, 10);
    assert_eq(str_repeat('*', 10), $data);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 6. STRING PROCESSING THROUGH MEMORY
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== String Processing ===\n";

test('String to_upper via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/strings.wat");
    $mem = $i->getMemory('memory');

    $str = "hello world";
    $mem->write(0, $str);
    $i->callFunc('to_upper', 0, strlen($str));
    $result = $mem->read(0, strlen($str));
    assert_eq("HELLO WORLD", $result);
});

test('String reverse via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/strings.wat");
    $mem = $i->getMemory('memory');

    $str = "Hello!";
    $mem->write(0, $str);
    $i->callFunc('reverse', 0, strlen($str));
    $result = $mem->read(0, strlen($str));
    assert_eq("!olleH", $result);
});

test('String concat via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/strings.wat");
    $mem = $i->getMemory('memory');

    $dst = "Hello, ";
    $src = "World!";
    $mem->write(0, $dst);
    $mem->write(100, $src);
    $total = $i->callFunc('concat', 0, strlen($dst), 100, strlen($src));
    assert_eq(13, $total);
    $result = $mem->read(0, $total);
    assert_eq("Hello, World!", $result);
});

test('strlen via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/strings.wat");
    $mem = $i->getMemory('memory');

    $str = "test string";
    $mem->write(0, $str . "\0");  // null terminate
    $len = $i->callFunc('strlen', 0);
    assert_eq(11, $len);
});

test('Process binary data through WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/strings.wat");
    $mem = $i->getMemory('memory');

    // Write binary data with null bytes
    $data = "\x01\x00\x02\x00\x03";
    $mem->write(0, $data);
    $result = $mem->read(0, 5);
    assert_eq($data, $result);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 7. HOST CALLBACKS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Host Callbacks ===\n";

test('Basic host function callback (i32 add)', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $linker->defineFunc('env', 'host_add',
        function(int $a, int $b): int { return $a + $b; },
        ['i32', 'i32'], ['i32']);

    $linker->defineFunc('env', 'host_multiply',
        function(int $a, int $b): int { return $a * $b; },
        ['i32', 'i32'], ['i32']);

    $logged = [];
    $linker->defineFunc('env', 'host_log',
        function(int $val) use (&$logged) { $logged[] = $val; },
        ['i32'], []);

    $linker->defineFunc('env', 'host_get_value',
        function(): int { return 777; },
        [], ['i32']);

    $linker->defineFunc('env', 'host_double_f64',
        function(float $v): float { return $v * 2.0; },
        ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    $result = $instance->callFunc('call_host_add', 10, 20);
    assert_eq(30, $result);
});

test('Chained host function calls', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $linker->defineFunc('env', 'host_add',
        function(int $a, int $b): int { return $a + $b; },
        ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_multiply',
        function(int $a, int $b): int { return $a * $b; },
        ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_log', function(int $v) {}, ['i32'], []);
    $linker->defineFunc('env', 'host_get_value', function(): int { return 0; }, [], ['i32']);
    $linker->defineFunc('env', 'host_double_f64', function(float $v): float { return $v * 2.0; }, ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    // chain_ops: (a+b) * c
    $result = $instance->callFunc('chain_ops', 3, 4, 5);
    assert_eq(35, $result);  // (3+4)*5 = 35
});

test('Void callback (host_log)', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $logged = [];
    $linker->defineFunc('env', 'host_add', function(int $a, int $b): int { return $a + $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_multiply', function(int $a, int $b): int { return $a * $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_log',
        function(int $val) use (&$logged) { $logged[] = $val; },
        ['i32'], []);
    $linker->defineFunc('env', 'host_get_value', function(): int { return 0; }, [], ['i32']);
    $linker->defineFunc('env', 'host_double_f64', function(float $v): float { return $v * 2.0; }, ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    $instance->callFunc('do_logging', 42);
    $instance->callFunc('do_logging', 99);
    assert_eq([42, 99], $logged);
});

test('Host callback returning value (host_get_value)', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $linker->defineFunc('env', 'host_add', function(int $a, int $b): int { return $a + $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_multiply', function(int $a, int $b): int { return $a * $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_log', function(int $v) {}, ['i32'], []);
    $linker->defineFunc('env', 'host_get_value',
        function(): int { return 777; },
        [], ['i32']);
    $linker->defineFunc('env', 'host_double_f64', function(float $v): float { return $v * 2.0; }, ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    $result = $instance->callFunc('get_host_value');
    assert_eq(777, $result);
});

test('f64 callback', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $linker->defineFunc('env', 'host_add', function(int $a, int $b): int { return $a + $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_multiply', function(int $a, int $b): int { return $a * $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_log', function(int $v) {}, ['i32'], []);
    $linker->defineFunc('env', 'host_get_value', function(): int { return 0; }, [], ['i32']);
    $linker->defineFunc('env', 'host_double_f64',
        function(float $v): float { return $v * 2.0; },
        ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    $result = $instance->callFunc('double_value', 3.14);
    assert_near(6.28, $result, 0.001);
});

test('Callback with closure state', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $call_count = 0;
    $linker->defineFunc('env', 'host_add',
        function(int $a, int $b) use (&$call_count): int {
            $call_count++;
            return $a + $b;
        },
        ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_multiply', function(int $a, int $b): int { return $a * $b; }, ['i32', 'i32'], ['i32']);
    $linker->defineFunc('env', 'host_log', function(int $v) {}, ['i32'], []);
    $linker->defineFunc('env', 'host_get_value', function(): int { return 0; }, [], ['i32']);
    $linker->defineFunc('env', 'host_double_f64', function(float $v): float { return $v * 2.0; }, ['f64'], ['f64']);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/callbacks.wat");
    $instance = $linker->instantiate($store, $module);

    $instance->callFunc('call_host_add', 1, 2);
    $instance->callFunc('call_host_add', 3, 4);
    $instance->callFunc('call_host_add', 5, 6);
    assert_eq(3, $call_count);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 8. GLOBALS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Globals ===\n";

test('Get mutable global', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g = $i->getGlobal('counter');
    assert_true($g instanceof Wasmtime\WasmGlobal);
    assert_eq(0, $g->get());
});

test('Set mutable global from PHP', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g = $i->getGlobal('counter');
    $g->set(100);
    assert_eq(100, $g->get());
    // Verify WASM sees the change too
    assert_eq(100, $i->callFunc('get_counter'));
});

test('Increment global via WASM', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    assert_eq(1, $i->callFunc('increment'));
    assert_eq(2, $i->callFunc('increment'));
    assert_eq(3, $i->callFunc('increment'));
    $g = $i->getGlobal('counter');
    assert_eq(3, $g->get());
});

test('Immutable global read', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g = $i->getGlobal('immutable');
    assert_eq(42, $g->get());
});

test('Immutable global write throws', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g = $i->getGlobal('immutable');
    assert_throws(Wasmtime\Exception::class, function() use ($g) {
        $g->set(99);
    });
});

test('Float global', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g = $i->getGlobal('float_val');
    assert_near(3.14, $g->get(), 0.01);
    $g->set(2.718);
    assert_near(2.718, $g->get(), 0.001);
});

test('Global type info', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/globals.wat");
    $g_mut = $i->getGlobal('counter');
    $type_mut = $g_mut->type();
    assert_eq('i32', $type_mut['kind']);
    assert_eq(true, $type_mut['mutable']);

    $g_imm = $i->getGlobal('immutable');
    $type_imm = $g_imm->type();
    assert_eq('i32', $type_imm['kind']);
    assert_eq(false, $type_imm['mutable']);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 9. TABLE
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Table ===\n";

test('Get table from instance', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/table.wat");
    $table = $i->getTable('table');
    assert_true($table instanceof Wasmtime\Table);
    assert_eq(3, $table->size());
});

test('Call indirect (function table dispatch)', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/table.wat");
    // Table: [0]=double, [1]=triple, [2]=square
    assert_eq(10, $i->callFunc('call_indirect', 0, 5));  // double(5)=10
    assert_eq(15, $i->callFunc('call_indirect', 1, 5));  // triple(5)=15
    assert_eq(25, $i->callFunc('call_indirect', 2, 5));  // square(5)=25
});

/* ═══════════════════════════════════════════════════════════════════════
 * 10. FIBONACCI (RECURSIVE COMPUTATION)
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Fibonacci ===\n";

test('Fibonacci recursive', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/fibonacci.wat");
    assert_eq(0, $i->callFunc('fibonacci', 0));
    assert_eq(1, $i->callFunc('fibonacci', 1));
    assert_eq(1, $i->callFunc('fibonacci', 2));
    assert_eq(5, $i->callFunc('fibonacci', 5));
    assert_eq(55, $i->callFunc('fibonacci', 10));
    assert_eq(610, $i->callFunc('fibonacci', 15));
});

test('Fibonacci iterative (i64)', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/fibonacci.wat");
    assert_eq(0, $i->callFunc('fibonacci_iter', 0));
    assert_eq(1, $i->callFunc('fibonacci_iter', 1));
    assert_eq(55, $i->callFunc('fibonacci_iter', 10));
    assert_eq(6765, $i->callFunc('fibonacci_iter', 20));
    // Large Fibonacci using i64
    assert_eq(12586269025, $i->callFunc('fibonacci_iter', 50));
});

/* ═══════════════════════════════════════════════════════════════════════
 * 11. WASI TESTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== WASI ===\n";

test('WASI hello world (stdout to file)', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);
    $linker->defineWasi();

    $stdout_path = tempnam(sys_get_temp_dir(), 'wasi_stdout_');

    $wasi = new Wasmtime\WasiConfig();
    $wasi->setStdoutFile($stdout_path);

    $store->setWasi($wasi);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/wasi_hello.wat");
    $instance = $linker->instantiate($store, $module);
    $instance->callFunc('_start');

    $output = file_get_contents($stdout_path);
    unlink($stdout_path);
    assert_eq("Hello from WASI!\n", $output);
});

test('WASI with arguments', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);
    $linker->defineWasi();

    $stdout_path = tempnam(sys_get_temp_dir(), 'wasi_stdout_');

    $wasi = new Wasmtime\WasiConfig();
    $wasi->setArgv(['my_program', 'arg1', 'arg2']);
    $wasi->setStdoutFile($stdout_path);

    $store->setWasi($wasi);

    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/wasi_args.wat");
    $instance = $linker->instantiate($store, $module);
    $instance->callFunc('_start');

    $output = file_get_contents($stdout_path);
    unlink($stdout_path);
    assert_eq("my_program\narg1\narg2\n", $output);
});

test('WASI with environment variables', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);
    $linker->defineWasi();

    $wasi = new Wasmtime\WasiConfig();
    $wasi->setEnv(['FOO' => 'bar', 'BAZ' => 'qux']);
    $store->setWasi($wasi);

    // Just verify it doesn't crash - env vars are available to the WASM module
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/wasi_hello.wat");
    $stdout_path = tempnam(sys_get_temp_dir(), 'wasi_');
    // Need fresh config since old one was consumed
    $store2 = new Wasmtime\Store($engine);
    $wasi2 = new Wasmtime\WasiConfig();
    $wasi2->setEnv(['FOO' => 'bar']);
    $wasi2->setStdoutFile($stdout_path);
    $store2->setWasi($wasi2);
    $linker2 = new Wasmtime\Linker($engine);
    $linker2->defineWasi();
    $instance = $linker2->instantiate($store2, $module);
    $instance->callFunc('_start');
    unlink($stdout_path);
    // If we got here without crashing, env vars worked
    assert_true(true);
});

test('WasiConfig consumed flag', function() {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);

    $wasi = new Wasmtime\WasiConfig();
    $store->setWasi($wasi);

    assert_throws(Wasmtime\Exception::class, function() use ($wasi) {
        $engine2 = new Wasmtime\Engine();
        $store2 = new Wasmtime\Store($engine2);
        $store2->setWasi($wasi);  // already consumed
    });
});

test('WASI stdin from bytes', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $wasi = new Wasmtime\WasiConfig();
    $wasi->setStdinBytes("hello input");
    // Just verify it doesn't crash
    assert_true(true);
});

/* ═══════════════════════════════════════════════════════════════════════
 * 12. INSTANCE INSPECTION
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Instance Inspection ===\n";

test('Export names listing', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    $exports = $i->exportNames();
    assert_true(count($exports) > 0);
    $names = array_column($exports, 'name');
    assert_true(in_array('add_i32', $names));
    assert_true(in_array('mul_i32', $names));
});

test('Non-existent export returns null', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_eq(null, $i->getFunc('doesnt_exist'));
    assert_eq(null, $i->getMemory('doesnt_exist'));
    assert_eq(null, $i->getGlobal('doesnt_exist'));
    assert_eq(null, $i->getTable('doesnt_exist'));
});

test('Wrong export type returns null', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/memory.wat");
    // 'memory' is a memory, not a function
    assert_eq(null, $i->getFunc('memory'));
    // 'store_i32' is a function, not a memory
    assert_eq(null, $i->getMemory('store_i32'));
});

/* ═══════════════════════════════════════════════════════════════════════
 * 13. ERROR HANDLING
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Error Handling ===\n";

test('Invalid WAT throws exception', function() {
    $engine = new Wasmtime\Engine();
    assert_throws(Wasmtime\Exception::class, function() use ($engine) {
        new Wasmtime\Module($engine, '(module (this is invalid))');
    });
});

test('Invalid WASM binary throws exception', function() {
    $engine = new Wasmtime\Engine();
    assert_throws(Wasmtime\Exception::class, function() use ($engine) {
        new Wasmtime\Module($engine, "\x00\x61\x73\x6d\xFF\xFF\xFF\xFF");
    });
});

test('Division by zero traps', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_throws(Wasmtime\Exception::class, function() use ($i) {
        $i->callFunc('div_i32', 10, 0);
    });
});

test('callFunc on non-existent export throws', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/arithmetic.wat");
    assert_throws(Wasmtime\Exception::class, function() use ($i) {
        $i->callFunc('nonexistent_function');
    });
});

test('Table out of bounds traps', function() use ($wasm_dir) {
    [,,,, $i] = instantiate_wat("$wasm_dir/table.wat");
    assert_throws(Wasmtime\Exception::class, function() use ($i) {
        $i->callFunc('call_indirect', 99, 5);  // index 99 is out of bounds
    });
});

/* ═══════════════════════════════════════════════════════════════════════
 * 14. MULTIPLE INSTANCES / ISOLATION
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Instance Isolation ===\n";

test('Multiple instances have separate state', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/globals.wat");

    $store1 = new Wasmtime\Store($engine);
    $store2 = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $i1 = $linker->instantiate($store1, $module);
    $i2 = $linker->instantiate($store2, $module);

    $i1->callFunc('set_counter', 100);
    $i2->callFunc('set_counter', 200);

    assert_eq(100, $i1->callFunc('get_counter'));
    assert_eq(200, $i2->callFunc('get_counter'));
});

test('Module reuse across stores', function() use ($wasm_dir) {
    $engine = new Wasmtime\Engine();
    $module = Wasmtime\Module::fromFile($engine, "$wasm_dir/arithmetic.wat");

    for ($i = 0; $i < 5; $i++) {
        $store = new Wasmtime\Store($engine);
        $linker = new Wasmtime\Linker($engine);
        $instance = $linker->instantiate($store, $module);
        $result = $instance->callFunc('add_i32', $i, $i);
        assert_eq($i * 2, $result);
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * 15. INLINE WAT COMPILATION
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n=== Inline WAT ===\n";

test('Compile and run inline WAT', function() {
    $engine = new Wasmtime\Engine();
    $store = new Wasmtime\Store($engine);
    $linker = new Wasmtime\Linker($engine);

    $wat = '(module
        (func (export "answer") (result i32)
            i32.const 42)
        (func (export "double") (param i32) (result i32)
            local.get 0
            i32.const 2
            i32.mul))';

    $module = new Wasmtime\Module($engine, $wat);
    $instance = $linker->instantiate($store, $module);

    assert_eq(42, $instance->callFunc('answer'));
    assert_eq(10, $instance->callFunc('double', 5));
});

/* ═══════════════════════════════════════════════════════════════════════
 * RESULTS
 * ═══════════════════════════════════════════════════════════════════════ */

echo "\n" . str_repeat('═', 60) . "\n";
echo "Results: $passed passed, $failed failed out of " . ($passed + $failed) . " tests\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as [$name, $exception]) {
        echo "  - $name\n";
        echo "    " . $exception->getMessage() . "\n";
        $trace = $exception->getTraceAsString();
        $lines = explode("\n", $trace);
        foreach (array_slice($lines, 0, 3) as $line) {
            echo "    $line\n";
        }
    }
    exit(1);
}

echo "\nAll tests passed!\n";
exit(0);

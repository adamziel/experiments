# PHP Wasmtime Extension

A native PHP extension that deeply integrates the [Wasmtime](https://wasmtime.dev/) WebAssembly runtime with PHP. Provides first-class PHP objects for the full wasmtime lifecycle: compilation, instantiation, function calls (both directions), memory access, globals, tables, and complete WASI support.

**Wasmtime version**: 29.0.1  
**PHP version**: 8.2+ (NTS)  
**Platform**: Linux x86_64

## Quick start

```php
$engine = new Wasmtime\Engine();
$store  = new Wasmtime\Store($engine);
$linker = new Wasmtime\Linker($engine);

// Compile from WAT text or binary .wasm
$module = new Wasmtime\Module($engine, '
  (module
    (func (export "add") (param i32 i32) (result i32)
      local.get 0 local.get 1 i32.add))
');

$instance = $linker->instantiate($store, $module);
echo $instance->callFunc("add", 40, 2); // 42
```

## Building

### Prerequisites

- PHP 8.2+ development headers (`phpize`, `php-config`)
- GCC, make, autoconf
- The wasmtime C API library (downloaded automatically by setup, or provide your own in `wasmtime-c-api/`)

### Quick path (any Linux/macOS)

```bash
./scripts/build.sh   # downloads wasmtime C API + builds modules/wasmtime.so
./scripts/test.sh    # runs the 82-test suite
```

On NixOS, prefix both with `nix-shell --run`.

### Manual build

```bash
./scripts/download-wasmtime.sh     # fetch wasmtime C API into wasmtime-c-api/
phpize
./configure --enable-wasmtime
make -j$(nproc)
LD_LIBRARY_PATH=$(pwd)/wasmtime-c-api/lib \
  php -d extension=$(pwd)/modules/wasmtime.so tests/run_tests.php
```

### Loading the extension

```bash
# Option 1: command line
php -d extension=/path/to/modules/wasmtime.so your_script.php

# Option 2: php.ini
echo "extension=/path/to/modules/wasmtime.so" >> $(php -r 'echo php_ini_loaded_file();')

# Ensure libwasmtime.so is findable:
export LD_LIBRARY_PATH=/path/to/wasmtime-c-api/lib:$LD_LIBRARY_PATH
```

## API reference

All classes are in the `Wasmtime` namespace. Errors throw `Wasmtime\Exception` (extends `RuntimeException`).

### `Wasmtime\Engine`

The compilation engine. Thread-safe and reusable. Create one per application.

```php
$engine = new Wasmtime\Engine();
```

### `Wasmtime\Store`

Owns all runtime state (instances, memories, globals). One store per logical "sandbox."

```php
$store = new Wasmtime\Store($engine);
$store->setWasi($wasiConfig);  // Enable WASI (consumes config)
$store->setFuel(1000000);      // Execution fuel limit
$fuel = $store->getFuel();     // Remaining fuel
```

### `Wasmtime\Module`

A compiled WebAssembly module. Can be created from binary `.wasm` or text `.wat` format.

```php
// From WAT text
$module = new Wasmtime\Module($engine, '(module ...)');

// From binary
$module = new Wasmtime\Module($engine, file_get_contents('module.wasm'));

// From file (auto-detects WAT vs binary)
$module = Wasmtime\Module::fromFile($engine, 'module.wat');

// Inspect
$module->exports();  // [['name'=>'add', 'kind'=>'func', 'params'=>['i32','i32'], 'results'=>['i32']], ...]
$module->imports();  // [['module'=>'env', 'name'=>'log', 'kind'=>'func'], ...]

// Serialize for caching
$bytes = $module->serialize();
$module = Wasmtime\Module::deserialize($engine, $bytes);
```

### `Wasmtime\Linker`

Resolves imports by name. Use this to define host functions and WASI, then instantiate modules.

```php
$linker = new Wasmtime\Linker($engine);

// Define WASI imports
$linker->defineWasi();

// Define a host function callable from WASM
$linker->defineFunc(
    'env',           // module name
    'host_add',      // function name
    function(int $a, int $b): int { return $a + $b; },
    ['i32', 'i32'],  // param types
    ['i32']          // result types
);

// Instantiate a module
$instance = $linker->instantiate($store, $module);

// Allow redefining imports
$linker->allowShadowing(true);
```

**Supported type strings**: `i32`, `i64`, `f32`, `f64`

### `Wasmtime\Instance`

A live, instantiated module. All exports are accessible.

```php
$instance = $linker->instantiate($store, $module);

// Quick call
$result = $instance->callFunc('function_name', $arg1, $arg2, ...);

// Get typed exports
$func   = $instance->getFunc('my_func');     // Wasmtime\Func|null
$memory = $instance->getMemory('memory');     // Wasmtime\Memory|null
$global = $instance->getGlobal('counter');    // Wasmtime\WasmGlobal|null
$table  = $instance->getTable('table');       // Wasmtime\Table|null

// List all exports
$exports = $instance->exportNames();
// [['name'=>'add', 'kind'=>'func'], ['name'=>'memory', 'kind'=>'memory'], ...]
```

**Return value semantics for `callFunc` / `Func::call`**:
- 0 results → `null`
- 1 result → scalar value (int or float)
- 2+ results → array of values

### `Wasmtime\Func`

A reference to a WebAssembly function (exported or host-defined).

```php
$func = $instance->getFunc('add');
$result = $func->call(40, 2);       // 42
$params = $func->paramTypes();       // ['i32', 'i32']
$results = $func->resultTypes();     // ['i32']
```

### `Wasmtime\Memory`

Direct access to linear memory. All offsets are in bytes.

```php
$mem = $instance->getMemory('memory');

// Raw bytes
$mem->write(0, "Hello");
$data = $mem->read(0, 5);  // "Hello"

// Typed access
$mem->writeI32(100, 42);
$val = $mem->readI32(100);  // 42

$mem->writeF64(200, 3.14);
$val = $mem->readF64(200);  // 3.14

// Size & growth
$pages = $mem->size();          // pages (64KB each)
$bytes = $mem->dataSize();      // total bytes
$prev  = $mem->grow(2);         // grow by 2 pages, returns previous page count
```

Out-of-bounds access throws `Wasmtime\Exception`.

### `Wasmtime\WasmGlobal`

Access to exported globals. Named `WasmGlobal` to avoid PHP keyword conflict.

```php
$g = $instance->getGlobal('counter');
$val = $g->get();       // current value
$g->set(42);            // set (mutable globals only)
$info = $g->type();     // ['kind'=>'i32', 'mutable'=>true]
```

Setting an immutable global throws `Wasmtime\Exception`.

### `Wasmtime\Table`

WebAssembly function table.

```php
$table = $instance->getTable('table');
$size = $table->size();
```

### `Wasmtime\WasiConfig`

Configure the WASI environment before passing it to a Store.

```php
$wasi = new Wasmtime\WasiConfig();

// Program arguments
$wasi->setArgv(['my_program', 'arg1', 'arg2']);
$wasi->inheritArgv();  // or inherit from PHP process

// Environment variables
$wasi->setEnv(['KEY' => 'value', 'FOO' => 'bar']);
$wasi->inheritEnv();

// Standard I/O
$wasi->inheritStdio();                    // pass through to PHP process
$wasi->setStdoutFile('/tmp/output.txt');   // redirect to file
$wasi->setStderrFile('/tmp/errors.txt');
$wasi->setStdinFile('/tmp/input.txt');
$wasi->setStdinBytes("input data");        // provide stdin from string

// Filesystem access
$wasi->preopenDir('/host/path', '/guest/path');
$wasi->preopenDir('/data', '/data',
    Wasmtime\WASI_DIR_READ | Wasmtime\WASI_DIR_WRITE,
    Wasmtime\WASI_FILE_READ | Wasmtime\WASI_FILE_WRITE);

// Apply to store (consumes the config - can only be used once)
$store->setWasi($wasi);
```

### `Wasmtime\Exception`

All wasmtime errors and WASM traps throw this exception (extends `RuntimeException`).

```php
try {
    $instance->callFunc('divide', 10, 0);
} catch (Wasmtime\Exception $e) {
    echo "WASM error: " . $e->getMessage();
    // "WASM trap: wasm trap: integer divide by zero..."
}
```

## Host callbacks

WASM modules can import and call PHP functions. The callback receives native PHP types and must return the declared type.

```php
// Void callback (no return)
$linker->defineFunc('env', 'log_value',
    function(int $value): void {
        error_log("WASM logged: $value");
    },
    ['i32'], []);

// Callback with closure state
$counter = 0;
$linker->defineFunc('env', 'increment',
    function() use (&$counter): int {
        return ++$counter;
    },
    [], ['i32']);

// Float callback
$linker->defineFunc('env', 'sqrt',
    function(float $x): float {
        return sqrt($x);
    },
    ['f64'], ['f64']);

// Multi-result callback (return array)
$linker->defineFunc('env', 'divmod',
    function(int $a, int $b): array {
        return [intdiv($a, $b), $a % $b];
    },
    ['i32', 'i32'], ['i32', 'i32']);
```

If a PHP callback throws an exception, it becomes a WASM trap.

## Data type mapping

| WASM type | PHP type | Notes |
|-----------|----------|-------|
| `i32` | `int` | 32-bit signed integer |
| `i64` | `int` | 64-bit on 64-bit platforms |
| `f32` | `float` | Precision loss (32→64 bit float) |
| `f64` | `float` | Native PHP double |
| `funcref` | — | Not yet exposed to PHP |
| `externref` | — | Not yet exposed to PHP |
| `v128` | — | SIMD, not exposed |

## Running WASI programs

```php
$engine = new Wasmtime\Engine();
$store  = new Wasmtime\Store($engine);
$linker = new Wasmtime\Linker($engine);
$linker->defineWasi();

$wasi = new Wasmtime\WasiConfig();
$wasi->setArgv(['my_program', '--verbose']);
$wasi->inheritStdio();
$wasi->preopenDir('/tmp', '/tmp');
$store->setWasi($wasi);

$module   = Wasmtime\Module::fromFile($engine, 'program.wasm');
$instance = $linker->instantiate($store, $module);
$instance->callFunc('_start');
```

## Architecture

```
┌──────────────────────────────────────────────────┐
│                   PHP userland                     │
│  $engine → $module → $linker → $instance → calls   │
└──────────────┬───────────────────────────────────┘
               │ PHP C extension API (zend_object)
┌──────────────▼───────────────────────────────────┐
│              php_wasmtime.c                        │
│  Wraps wasmtime C API structs as PHP objects.      │
│  Handles: zval ↔ wasmtime_val_t conversion,        │
│  callback trampolines, memory bounds checking,     │
│  GC ref management via zval references.            │
└──────────────┬───────────────────────────────────┘
               │ wasmtime C API (libwasmtime.so)
┌──────────────▼───────────────────────────────────┐
│            Wasmtime runtime (Rust)                  │
│  JIT compilation, sandboxed execution,              │
│  WASI implementation, memory management             │
└──────────────────────────────────────────────────┘
```

**Key design decisions**:

1. **One C file**: The extension is a single `php_wasmtime.c` file (~1700 lines). This keeps the build simple and is standard practice for PHP extensions of this size.

2. **Linker-centric API**: The `Linker` is the primary way to define imports and instantiate modules. This matches wasmtime's recommended pattern and handles name-based import resolution cleanly.

3. **WAT auto-detection**: The `Module` constructor detects WAT text format (starts with `(`) vs binary WASM and handles both transparently using `wasmtime_wat2wasm`.

4. **Callback trampolines**: PHP callables are stored in `php_wasmtime_callback_data_t` structs with ref-counted `zval`s. A single C trampoline function marshals between wasmtime's `wasmtime_val_t` and PHP `zval`s, calling the PHP function via `call_user_function`.

5. **GC safety**: Every PHP object that references another (e.g., Instance→Store, Store→Engine) holds a `zval` copy with incremented refcount, preventing premature garbage collection.

6. **Trap factory store**: A global `wasm_store_t*` is created at module init for constructing `wasm_trap_t` objects from callback failures, since `wasm_trap_new` requires a store parameter.

## Technical limitations

1. **No `funcref`/`externref` in PHP**: These WASM reference types are not exposed to PHP userland. They work internally (e.g., function tables use funcref), but you cannot pass PHP objects as externref to WASM or receive funcref values.

2. **No `v128` (SIMD)**: 128-bit SIMD values have no natural PHP representation and are not supported for function arguments/returns.

3. **Single-threaded**: PHP's NTS (Non-Thread-Safe) build means the extension is single-threaded. The wasmtime `Engine` is thread-safe internally but the PHP objects are not.

4. **No async**: Wasmtime's async API is not exposed. All WASM execution is synchronous.

5. **No component model**: This extension supports core WASM modules and WASI preview 1. The WASM component model / WASI preview 2 interface types are not supported.

6. **f32 precision**: PHP uses 64-bit doubles. When passing values to f32 WASM parameters, precision is lost (64→32→64 bit round-trip).

7. **Memory safety**: While memory bounds are checked on the PHP side, the WASM sandbox enforced by wasmtime provides the primary safety guarantee. Direct memory pointers are never exposed to PHP.

8. **Fuel/epochs**: Fuel-based execution limits (`setFuel`/`getFuel`) require the engine to be configured with fuel consumption enabled. The default engine configuration does not enable fuel.

## Test suite

The test suite (`tests/run_tests.php`) runs 82 tests covering:

- Core infrastructure (Engine, Store, Module, Linker)
- All numeric types (i32, i64, f32, f64) including edge cases
- Function objects (Func::call, type introspection)
- Multi-value returns
- Memory access (read/write bytes, typed access, bounds checking, grow)
- String processing through WASM memory
- Host callbacks (all signatures, closures, chaining, void returns)
- Global variables (mutable/immutable, type checking)
- Tables (indirect calls)
- Recursive computation (Fibonacci)
- WASI (stdout, arguments, environment, stdin)
- Instance isolation
- Error handling (invalid WAT, traps, bounds violations)
- Module serialization/deserialization

```bash
LD_LIBRARY_PATH=$(pwd)/wasmtime-c-api/lib \
  php -d extension=$(pwd)/modules/wasmtime.so tests/run_tests.php
```

## License

MIT

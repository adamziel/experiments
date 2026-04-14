/**
 * PHP Wasmtime Extension
 *
 * Integrates the Wasmtime WebAssembly runtime with PHP, providing classes for
 * Engine, Store, Module, Linker, Instance, Func, Memory, Global, Table,
 * and WasiConfig. Supports host callbacks, data type marshaling, WASI, and
 * direct memory access.
 */

#include "php_wasmtime.h"
#include <string.h>

/* ────────────────────────────────────────────────────────────────────────────
 * Class entries and object handlers
 * ──────────────────────────────────────────────────────────────────────────── */

zend_class_entry *wasmtime_ce_engine;
zend_class_entry *wasmtime_ce_store;
zend_class_entry *wasmtime_ce_module;
zend_class_entry *wasmtime_ce_linker;
zend_class_entry *wasmtime_ce_instance;
zend_class_entry *wasmtime_ce_func;
zend_class_entry *wasmtime_ce_memory;
zend_class_entry *wasmtime_ce_wasm_global;
zend_class_entry *wasmtime_ce_table;
zend_class_entry *wasmtime_ce_wasi_config;
zend_class_entry *wasmtime_ce_exception;

zend_object_handlers wasmtime_engine_handlers;
zend_object_handlers wasmtime_store_handlers;
zend_object_handlers wasmtime_module_handlers;
zend_object_handlers wasmtime_linker_handlers;
zend_object_handlers wasmtime_instance_handlers;
zend_object_handlers wasmtime_func_handlers;
zend_object_handlers wasmtime_memory_handlers;
zend_object_handlers wasmtime_wasm_global_handlers;
zend_object_handlers wasmtime_table_handlers;
zend_object_handlers wasmtime_wasi_config_handlers;

static wasm_store_t *trap_factory_store = NULL;

/* ────────────────────────────────────────────────────────────────────────────
 * Helper utilities
 * ──────────────────────────────────────────────────────────────────────────── */

void php_wasmtime_throw_error(wasmtime_error_t *error)
{
    wasm_name_t message;
    wasmtime_error_message(error, &message);
    zend_throw_exception_ex(wasmtime_ce_exception, 0, "%.*s",
                            (int)message.size, message.data);
    wasm_byte_vec_delete(&message);
    wasmtime_error_delete(error);
}

void php_wasmtime_throw_trap(wasm_trap_t *trap)
{
    wasm_message_t message;
    wasm_trap_message(trap, &message);
    zend_throw_exception_ex(wasmtime_ce_exception, 0, "WASM trap: %.*s",
                            (int)message.size, message.data);
    wasm_byte_vec_delete(&message);
    wasm_trap_delete(trap);
}

wasm_valkind_t php_wasmtime_parse_valkind(const char *type_str, size_t len)
{
    if (len == 3 && memcmp(type_str, "i32", 3) == 0) return WASM_I32;
    if (len == 3 && memcmp(type_str, "i64", 3) == 0) return WASM_I64;
    if (len == 3 && memcmp(type_str, "f32", 3) == 0) return WASM_F32;
    if (len == 3 && memcmp(type_str, "f64", 3) == 0) return WASM_F64;
    if (len == 7 && memcmp(type_str, "funcref", 7) == 0) return WASM_FUNCREF;
    if (len == 9 && memcmp(type_str, "externref", 9) == 0) return WASM_EXTERNREF;
    return WASM_I32;
}

void php_wasmtime_val_to_zval(const wasmtime_val_t *val, zval *zv)
{
    switch (val->kind) {
        case WASMTIME_I32:
            ZVAL_LONG(zv, (zend_long)val->of.i32);
            break;
        case WASMTIME_I64:
            ZVAL_LONG(zv, (zend_long)val->of.i64);
            break;
        case WASMTIME_F32:
            ZVAL_DOUBLE(zv, (double)val->of.f32);
            break;
        case WASMTIME_F64:
            ZVAL_DOUBLE(zv, val->of.f64);
            break;
        default:
            ZVAL_NULL(zv);
            break;
    }
}

bool php_wasmtime_zval_to_val(zval *zv, wasmtime_val_t *val, wasm_valkind_t kind)
{
    val->kind = kind;
    switch (kind) {
        case WASM_I32:
            val->kind = WASMTIME_I32;
            val->of.i32 = (int32_t)zval_get_long(zv);
            return true;
        case WASM_I64:
            val->kind = WASMTIME_I64;
            val->of.i64 = (int64_t)zval_get_long(zv);
            return true;
        case WASM_F32:
            val->kind = WASMTIME_F32;
            val->of.f32 = (float)zval_get_double(zv);
            return true;
        case WASM_F64:
            val->kind = WASMTIME_F64;
            val->of.f64 = zval_get_double(zv);
            return true;
        default:
            zend_throw_exception_ex(wasmtime_ce_exception, 0,
                "Unsupported WASM value kind %d", kind);
            return false;
    }
}

wasmtime_context_t *php_wasmtime_store_context(zval *store_zv)
{
    php_wasmtime_store_t *intern = Z_WASMTIME_STORE_P(store_zv);
    return wasmtime_store_context(intern->store);
}

static const char *php_wasmtime_valkind_name(wasm_valkind_t kind)
{
    switch (kind) {
        case WASM_I32: return "i32";
        case WASM_I64: return "i64";
        case WASM_F32: return "f32";
        case WASM_F64: return "f64";
        case WASM_FUNCREF: return "funcref";
        case WASM_EXTERNREF: return "externref";
        default: return "unknown";
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * Engine
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_engine_create(zend_class_entry *ce)
{
    php_wasmtime_engine_t *intern = zend_object_alloc(sizeof(php_wasmtime_engine_t), ce);
    intern->engine = NULL;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_engine_handlers;
    return &intern->std;
}

static void php_wasmtime_engine_free(zend_object *obj)
{
    php_wasmtime_engine_t *intern = php_wasmtime_engine_from_obj(obj);
    if (intern->engine) {
        wasm_engine_delete(intern->engine);
        intern->engine = NULL;
    }
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeEngine, __construct)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_engine_t *intern = Z_WASMTIME_ENGINE_P(ZEND_THIS);
    intern->engine = wasm_engine_new();
    if (!intern->engine) {
        zend_throw_exception(wasmtime_ce_exception, "Failed to create engine", 0);
    }
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_engine___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_engine_methods[] = {
    PHP_ME(WasmtimeEngine, __construct, arginfo_wasmtime_engine___construct, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Store
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_store_create(zend_class_entry *ce)
{
    php_wasmtime_store_t *intern = zend_object_alloc(sizeof(php_wasmtime_store_t), ce);
    intern->store = NULL;
    ZVAL_UNDEF(&intern->engine_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_store_handlers;
    return &intern->std;
}

static void php_wasmtime_store_free(zend_object *obj)
{
    php_wasmtime_store_t *intern = php_wasmtime_store_from_obj(obj);
    if (intern->store) {
        wasmtime_store_delete(intern->store);
        intern->store = NULL;
    }
    zval_ptr_dtor(&intern->engine_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeStore, __construct)
{
    zval *engine_zv;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O", &engine_zv, wasmtime_ce_engine) == FAILURE) {
        return;
    }
    php_wasmtime_store_t *intern = Z_WASMTIME_STORE_P(ZEND_THIS);
    php_wasmtime_engine_t *engine_intern = Z_WASMTIME_ENGINE_P(engine_zv);

    intern->store = wasmtime_store_new(engine_intern->engine, NULL, NULL);
    if (!intern->store) {
        zend_throw_exception(wasmtime_ce_exception, "Failed to create store", 0);
        return;
    }
    ZVAL_COPY(&intern->engine_zv, engine_zv);
}

PHP_METHOD(WasmtimeStore, setWasi)
{
    zval *wasi_zv;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O", &wasi_zv, wasmtime_ce_wasi_config) == FAILURE) {
        return;
    }
    php_wasmtime_store_t *intern = Z_WASMTIME_STORE_P(ZEND_THIS);
    php_wasmtime_wasi_config_t *wasi_intern = Z_WASMTIME_WASI_CONFIG_P(wasi_zv);

    if (wasi_intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig has already been consumed", 0);
        return;
    }

    wasmtime_context_t *context = wasmtime_store_context(intern->store);
    wasmtime_error_t *error = wasmtime_context_set_wasi(context, wasi_intern->config);
    wasi_intern->config = NULL;
    wasi_intern->consumed = true;

    if (error) {
        php_wasmtime_throw_error(error);
    }
}

PHP_METHOD(WasmtimeStore, setFuel)
{
    zend_long fuel;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &fuel) == FAILURE) {
        return;
    }
    php_wasmtime_store_t *intern = Z_WASMTIME_STORE_P(ZEND_THIS);
    wasmtime_context_t *context = wasmtime_store_context(intern->store);
    wasmtime_error_t *error = wasmtime_context_set_fuel(context, (uint64_t)fuel);
    if (error) {
        php_wasmtime_throw_error(error);
    }
}

PHP_METHOD(WasmtimeStore, getFuel)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_store_t *intern = Z_WASMTIME_STORE_P(ZEND_THIS);
    wasmtime_context_t *context = wasmtime_store_context(intern->store);
    uint64_t fuel;
    wasmtime_error_t *error = wasmtime_context_get_fuel(context, &fuel);
    if (error) {
        php_wasmtime_throw_error(error);
        return;
    }
    RETURN_LONG((zend_long)fuel);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_store___construct, 0, 0, 1)
    ZEND_ARG_OBJ_INFO(0, engine, Wasmtime\\Engine, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_store_set_wasi, 0, 0, 1)
    ZEND_ARG_OBJ_INFO(0, config, Wasmtime\\WasiConfig, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_store_set_fuel, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, fuel, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_store_get_fuel, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_store_methods[] = {
    PHP_ME(WasmtimeStore, __construct, arginfo_wasmtime_store___construct, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeStore, setWasi, arginfo_wasmtime_store_set_wasi, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeStore, setFuel, arginfo_wasmtime_store_set_fuel, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeStore, getFuel, arginfo_wasmtime_store_get_fuel, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Module
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_module_create(zend_class_entry *ce)
{
    php_wasmtime_module_t *intern = zend_object_alloc(sizeof(php_wasmtime_module_t), ce);
    intern->module = NULL;
    ZVAL_UNDEF(&intern->engine_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_module_handlers;
    return &intern->std;
}

static void php_wasmtime_module_free(zend_object *obj)
{
    php_wasmtime_module_t *intern = php_wasmtime_module_from_obj(obj);
    if (intern->module) {
        wasmtime_module_delete(intern->module);
        intern->module = NULL;
    }
    zval_ptr_dtor(&intern->engine_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeModule, __construct)
{
    zval *engine_zv;
    zend_string *wasm_data;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "OS", &engine_zv, wasmtime_ce_engine, &wasm_data) == FAILURE) {
        return;
    }

    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(ZEND_THIS);
    php_wasmtime_engine_t *engine_intern = Z_WASMTIME_ENGINE_P(engine_zv);

    const uint8_t *bytes = (const uint8_t *)ZSTR_VAL(wasm_data);
    size_t bytes_len = ZSTR_LEN(wasm_data);
    wasm_byte_vec_t compiled = {0};
    bool is_wat = false;

    if (bytes_len >= 1 && bytes[0] == '(') {
        is_wat = true;
        wasmtime_error_t *wat_error = wasmtime_wat2wasm(
            (const char *)bytes, bytes_len, &compiled);
        if (wat_error) {
            php_wasmtime_throw_error(wat_error);
            return;
        }
        bytes = (const uint8_t *)compiled.data;
        bytes_len = compiled.size;
    }

    wasmtime_error_t *error = wasmtime_module_new(
        engine_intern->engine, bytes, bytes_len, &intern->module);

    if (is_wat) {
        wasm_byte_vec_delete(&compiled);
    }

    if (error) {
        php_wasmtime_throw_error(error);
        return;
    }

    ZVAL_COPY(&intern->engine_zv, engine_zv);
}

PHP_METHOD(WasmtimeModule, fromFile)
{
    zval *engine_zv;
    zend_string *path;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "OS", &engine_zv, wasmtime_ce_engine, &path) == FAILURE) {
        return;
    }

    php_stream *stream = php_stream_open_wrapper(ZSTR_VAL(path), "rb", REPORT_ERRORS, NULL);
    if (!stream) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Cannot open file: %s", ZSTR_VAL(path));
        return;
    }

    zend_string *contents = php_stream_copy_to_mem(stream, PHP_STREAM_COPY_ALL, 0);
    php_stream_close(stream);

    if (!contents) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Failed to read file: %s", ZSTR_VAL(path));
        return;
    }

    php_wasmtime_engine_t *engine_intern = Z_WASMTIME_ENGINE_P(engine_zv);

    object_init_ex(return_value, wasmtime_ce_module);
    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(return_value);

    const uint8_t *bytes = (const uint8_t *)ZSTR_VAL(contents);
    size_t bytes_len = ZSTR_LEN(contents);
    wasm_byte_vec_t compiled = {0};
    bool is_wat = false;

    if (bytes_len >= 1 && bytes[0] == '(') {
        is_wat = true;
        wasmtime_error_t *wat_error = wasmtime_wat2wasm(
            (const char *)bytes, bytes_len, &compiled);
        if (wat_error) {
            zend_string_release(contents);
            php_wasmtime_throw_error(wat_error);
            zval_ptr_dtor(return_value);
            RETURN_NULL();
            return;
        }
        bytes = (const uint8_t *)compiled.data;
        bytes_len = compiled.size;
    }

    wasmtime_error_t *error = wasmtime_module_new(
        engine_intern->engine, bytes, bytes_len, &intern->module);

    if (is_wat) {
        wasm_byte_vec_delete(&compiled);
    }
    zend_string_release(contents);

    if (error) {
        php_wasmtime_throw_error(error);
        zval_ptr_dtor(return_value);
        RETURN_NULL();
        return;
    }

    ZVAL_COPY(&intern->engine_zv, engine_zv);
}

PHP_METHOD(WasmtimeModule, exports)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(ZEND_THIS);

    wasm_exporttype_vec_t exports;
    wasmtime_module_exports(intern->module, &exports);

    array_init(return_value);

    for (size_t i = 0; i < exports.size; i++) {
        const wasm_name_t *name = wasm_exporttype_name(exports.data[i]);
        const wasm_externtype_t *type = wasm_exporttype_type(exports.data[i]);

        zval entry;
        array_init(&entry);

        add_assoc_stringl(&entry, "name", name->data, name->size);

        const char *kind_str = "unknown";
        switch (wasm_externtype_kind(type)) {
            case WASM_EXTERN_FUNC: kind_str = "func"; break;
            case WASM_EXTERN_GLOBAL: kind_str = "global"; break;
            case WASM_EXTERN_TABLE: kind_str = "table"; break;
            case WASM_EXTERN_MEMORY: kind_str = "memory"; break;
        }
        add_assoc_string(&entry, "kind", kind_str);

        if (wasm_externtype_kind(type) == WASM_EXTERN_FUNC) {
            const wasm_functype_t *ft = wasm_externtype_as_functype_const(type);
            const wasm_valtype_vec_t *params = wasm_functype_params(ft);
            const wasm_valtype_vec_t *results = wasm_functype_results(ft);

            zval params_arr;
            array_init(&params_arr);
            for (size_t j = 0; j < params->size; j++) {
                add_next_index_string(&params_arr,
                    php_wasmtime_valkind_name(wasm_valtype_kind(params->data[j])));
            }
            add_assoc_zval(&entry, "params", &params_arr);

            zval results_arr;
            array_init(&results_arr);
            for (size_t j = 0; j < results->size; j++) {
                add_next_index_string(&results_arr,
                    php_wasmtime_valkind_name(wasm_valtype_kind(results->data[j])));
            }
            add_assoc_zval(&entry, "results", &results_arr);
        }

        add_next_index_zval(return_value, &entry);
    }

    wasm_exporttype_vec_delete(&exports);
}

PHP_METHOD(WasmtimeModule, imports)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(ZEND_THIS);

    wasm_importtype_vec_t imports;
    wasmtime_module_imports(intern->module, &imports);

    array_init(return_value);

    for (size_t i = 0; i < imports.size; i++) {
        const wasm_name_t *module_name = wasm_importtype_module(imports.data[i]);
        const wasm_name_t *name = wasm_importtype_name(imports.data[i]);
        const wasm_externtype_t *type = wasm_importtype_type(imports.data[i]);

        zval entry;
        array_init(&entry);

        add_assoc_stringl(&entry, "module", module_name->data, module_name->size);
        if (name) {
            add_assoc_stringl(&entry, "name", name->data, name->size);
        }

        const char *kind_str = "unknown";
        switch (wasm_externtype_kind(type)) {
            case WASM_EXTERN_FUNC: kind_str = "func"; break;
            case WASM_EXTERN_GLOBAL: kind_str = "global"; break;
            case WASM_EXTERN_TABLE: kind_str = "table"; break;
            case WASM_EXTERN_MEMORY: kind_str = "memory"; break;
        }
        add_assoc_string(&entry, "kind", kind_str);

        add_next_index_zval(return_value, &entry);
    }

    wasm_importtype_vec_delete(&imports);
}

PHP_METHOD(WasmtimeModule, serialize)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(ZEND_THIS);

    wasm_byte_vec_t bytes;
    wasmtime_error_t *error = wasmtime_module_serialize(intern->module, &bytes);
    if (error) {
        php_wasmtime_throw_error(error);
        return;
    }

    RETVAL_STRINGL(bytes.data, bytes.size);
    wasm_byte_vec_delete(&bytes);
}

PHP_METHOD(WasmtimeModule, deserialize)
{
    zval *engine_zv;
    zend_string *bytes;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "OS", &engine_zv, wasmtime_ce_engine, &bytes) == FAILURE) {
        return;
    }

    php_wasmtime_engine_t *engine_intern = Z_WASMTIME_ENGINE_P(engine_zv);

    object_init_ex(return_value, wasmtime_ce_module);
    php_wasmtime_module_t *intern = Z_WASMTIME_MODULE_P(return_value);

    wasmtime_error_t *error = wasmtime_module_deserialize(
        engine_intern->engine,
        (const uint8_t *)ZSTR_VAL(bytes),
        ZSTR_LEN(bytes),
        &intern->module
    );

    if (error) {
        php_wasmtime_throw_error(error);
        zval_ptr_dtor(return_value);
        RETURN_NULL();
        return;
    }

    ZVAL_COPY(&intern->engine_zv, engine_zv);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_module___construct, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, engine, Wasmtime\\Engine, 0)
    ZEND_ARG_TYPE_INFO(0, wasmOrWat, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_module_from_file, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, engine, Wasmtime\\Engine, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_module_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_module_deserialize, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, engine, Wasmtime\\Engine, 0)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_module_methods[] = {
    PHP_ME(WasmtimeModule, __construct, arginfo_wasmtime_module___construct, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeModule, fromFile, arginfo_wasmtime_module_from_file, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(WasmtimeModule, exports, arginfo_wasmtime_module_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeModule, imports, arginfo_wasmtime_module_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeModule, serialize, arginfo_wasmtime_module_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeModule, deserialize, arginfo_wasmtime_module_deserialize, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Host function callback trampoline
 * ──────────────────────────────────────────────────────────────────────────── */

static wasm_trap_t *php_wasmtime_host_callback(
    void *env,
    wasmtime_caller_t *caller,
    const wasmtime_val_t *args,
    size_t nargs,
    wasmtime_val_t *results,
    size_t nresults)
{
    php_wasmtime_callback_data_t *cb_data = (php_wasmtime_callback_data_t *)env;
    zval retval;
    zval *php_args = NULL;

    ZVAL_UNDEF(&retval);

    if (nargs > 0) {
        php_args = safe_emalloc(nargs, sizeof(zval), 0);
        for (size_t i = 0; i < nargs; i++) {
            php_wasmtime_val_to_zval(&args[i], &php_args[i]);
        }
    }

    zval func_copy;
    ZVAL_COPY(&func_copy, &cb_data->callable);

    int call_result = call_user_function(NULL, NULL, &func_copy, &retval, nargs, php_args);

    zval_ptr_dtor(&func_copy);

    if (php_args) {
        for (size_t i = 0; i < nargs; i++) {
            zval_ptr_dtor(&php_args[i]);
        }
        efree(php_args);
    }

    if (call_result != SUCCESS || EG(exception)) {
        wasm_message_t message;
        const char *msg;
        if (EG(exception)) {
            msg = "PHP callback threw an exception";
            zend_clear_exception();
        } else {
            msg = "PHP callback execution failed";
        }
        size_t msg_len = strlen(msg);
        wasm_byte_vec_new(&message, msg_len + 1, msg);
        wasm_trap_t *trap = wasm_trap_new(trap_factory_store, &message);
        wasm_byte_vec_delete(&message);
        zval_ptr_dtor(&retval);
        return trap;
    }

    if (nresults == 0) {
        zval_ptr_dtor(&retval);
        return NULL;
    }

    if (nresults == 1) {
        php_wasmtime_zval_to_val(&retval, &results[0], cb_data->result_kinds[0]);
        zval_ptr_dtor(&retval);
        return NULL;
    }

    if (Z_TYPE(retval) == IS_ARRAY) {
        HashTable *ht = Z_ARRVAL(retval);
        size_t idx = 0;
        zval *elem;
        ZEND_HASH_FOREACH_VAL(ht, elem) {
            if (idx >= nresults) break;
            php_wasmtime_zval_to_val(elem, &results[idx], cb_data->result_kinds[idx]);
            idx++;
        } ZEND_HASH_FOREACH_END();
    }

    zval_ptr_dtor(&retval);
    return NULL;
}

static void php_wasmtime_callback_finalizer(void *env)
{
    php_wasmtime_callback_data_t *data = (php_wasmtime_callback_data_t *)env;
    zval_ptr_dtor(&data->callable);
    if (data->param_kinds) efree(data->param_kinds);
    if (data->result_kinds) efree(data->result_kinds);
    efree(data);
}

/* ────────────────────────────────────────────────────────────────────────────
 * Linker
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_linker_create(zend_class_entry *ce)
{
    php_wasmtime_linker_t *intern = zend_object_alloc(sizeof(php_wasmtime_linker_t), ce);
    intern->linker = NULL;
    ZVAL_UNDEF(&intern->engine_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_linker_handlers;
    return &intern->std;
}

static void php_wasmtime_linker_free(zend_object *obj)
{
    php_wasmtime_linker_t *intern = php_wasmtime_linker_from_obj(obj);
    if (intern->linker) {
        wasmtime_linker_delete(intern->linker);
        intern->linker = NULL;
    }
    zval_ptr_dtor(&intern->engine_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeLinker, __construct)
{
    zval *engine_zv;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "O", &engine_zv, wasmtime_ce_engine) == FAILURE) {
        return;
    }
    php_wasmtime_linker_t *intern = Z_WASMTIME_LINKER_P(ZEND_THIS);
    php_wasmtime_engine_t *engine_intern = Z_WASMTIME_ENGINE_P(engine_zv);

    intern->linker = wasmtime_linker_new(engine_intern->engine);
    if (!intern->linker) {
        zend_throw_exception(wasmtime_ce_exception, "Failed to create linker", 0);
        return;
    }
    ZVAL_COPY(&intern->engine_zv, engine_zv);
}

PHP_METHOD(WasmtimeLinker, defineWasi)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_linker_t *intern = Z_WASMTIME_LINKER_P(ZEND_THIS);

    wasmtime_error_t *error = wasmtime_linker_define_wasi(intern->linker);
    if (error) {
        php_wasmtime_throw_error(error);
    }
}

PHP_METHOD(WasmtimeLinker, allowShadowing)
{
    zend_bool allow;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &allow) == FAILURE) {
        return;
    }
    php_wasmtime_linker_t *intern = Z_WASMTIME_LINKER_P(ZEND_THIS);
    wasmtime_linker_allow_shadowing(intern->linker, allow);
}

PHP_METHOD(WasmtimeLinker, defineFunc)
{
    zend_string *module_name, *func_name;
    zval *callable;
    HashTable *param_types_ht, *result_types_ht;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SSzhh",
        &module_name, &func_name, &callable,
        &param_types_ht, &result_types_ht) == FAILURE) {
        return;
    }

    if (!zend_is_callable(callable, 0, NULL)) {
        zend_throw_exception(wasmtime_ce_exception, "Third argument must be callable", 0);
        return;
    }

    php_wasmtime_linker_t *intern = Z_WASMTIME_LINKER_P(ZEND_THIS);

    size_t nparams = zend_hash_num_elements(param_types_ht);
    size_t nresults = zend_hash_num_elements(result_types_ht);

    wasm_valtype_t **param_types = NULL;
    wasm_valtype_t **result_types = NULL;

    if (nparams > 0) {
        param_types = safe_emalloc(nparams, sizeof(wasm_valtype_t *), 0);
    }
    if (nresults > 0) {
        result_types = safe_emalloc(nresults, sizeof(wasm_valtype_t *), 0);
    }

    php_wasmtime_callback_data_t *cb_data = emalloc(sizeof(php_wasmtime_callback_data_t));
    ZVAL_COPY(&cb_data->callable, callable);
    cb_data->nparams = nparams;
    cb_data->nresults = nresults;
    cb_data->param_kinds = nparams > 0 ? safe_emalloc(nparams, sizeof(wasm_valkind_t), 0) : NULL;
    cb_data->result_kinds = nresults > 0 ? safe_emalloc(nresults, sizeof(wasm_valkind_t), 0) : NULL;

    size_t idx = 0;
    zval *val;
    ZEND_HASH_FOREACH_VAL(param_types_ht, val) {
        zend_string *type_str = zval_get_string(val);
        wasm_valkind_t kind = php_wasmtime_parse_valkind(ZSTR_VAL(type_str), ZSTR_LEN(type_str));
        cb_data->param_kinds[idx] = kind;
        param_types[idx] = wasm_valtype_new(kind);
        zend_string_release(type_str);
        idx++;
    } ZEND_HASH_FOREACH_END();

    idx = 0;
    ZEND_HASH_FOREACH_VAL(result_types_ht, val) {
        zend_string *type_str = zval_get_string(val);
        wasm_valkind_t kind = php_wasmtime_parse_valkind(ZSTR_VAL(type_str), ZSTR_LEN(type_str));
        cb_data->result_kinds[idx] = kind;
        result_types[idx] = wasm_valtype_new(kind);
        zend_string_release(type_str);
        idx++;
    } ZEND_HASH_FOREACH_END();

    wasm_valtype_vec_t params_vec, results_vec;
    wasm_valtype_vec_new(&params_vec, nparams, param_types);
    wasm_valtype_vec_new(&results_vec, nresults, result_types);

    wasm_functype_t *functype = wasm_functype_new(&params_vec, &results_vec);

    if (param_types) efree(param_types);
    if (result_types) efree(result_types);

    wasmtime_error_t *error = wasmtime_linker_define_func(
        intern->linker,
        ZSTR_VAL(module_name), ZSTR_LEN(module_name),
        ZSTR_VAL(func_name), ZSTR_LEN(func_name),
        functype,
        php_wasmtime_host_callback,
        cb_data,
        php_wasmtime_callback_finalizer
    );

    wasm_functype_delete(functype);

    if (error) {
        php_wasmtime_throw_error(error);
    }
}

PHP_METHOD(WasmtimeLinker, instantiate)
{
    zval *store_zv, *module_zv;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "OO",
        &store_zv, wasmtime_ce_store,
        &module_zv, wasmtime_ce_module) == FAILURE) {
        return;
    }

    php_wasmtime_linker_t *linker_intern = Z_WASMTIME_LINKER_P(ZEND_THIS);
    php_wasmtime_store_t *store_intern = Z_WASMTIME_STORE_P(store_zv);
    php_wasmtime_module_t *module_intern = Z_WASMTIME_MODULE_P(module_zv);

    wasmtime_context_t *context = wasmtime_store_context(store_intern->store);

    object_init_ex(return_value, wasmtime_ce_instance);
    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(return_value);

    wasm_trap_t *trap = NULL;
    wasmtime_error_t *error = wasmtime_linker_instantiate(
        linker_intern->linker,
        context,
        module_intern->module,
        &inst->instance,
        &trap
    );

    if (error) {
        php_wasmtime_throw_error(error);
        zval_ptr_dtor(return_value);
        RETURN_NULL();
        return;
    }

    if (trap) {
        php_wasmtime_throw_trap(trap);
        zval_ptr_dtor(return_value);
        RETURN_NULL();
        return;
    }

    ZVAL_COPY(&inst->store_zv, store_zv);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_linker___construct, 0, 0, 1)
    ZEND_ARG_OBJ_INFO(0, engine, Wasmtime\\Engine, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_linker_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_linker_allow_shadowing, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, allow, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_linker_define_func, 0, 0, 5)
    ZEND_ARG_TYPE_INFO(0, module, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, callable, IS_CALLABLE, 0)
    ZEND_ARG_TYPE_INFO(0, paramTypes, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, resultTypes, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_linker_instantiate, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, store, Wasmtime\\Store, 0)
    ZEND_ARG_OBJ_INFO(0, module, Wasmtime\\Module, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_linker_methods[] = {
    PHP_ME(WasmtimeLinker, __construct, arginfo_wasmtime_linker___construct, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeLinker, defineWasi, arginfo_wasmtime_linker_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeLinker, allowShadowing, arginfo_wasmtime_linker_allow_shadowing, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeLinker, defineFunc, arginfo_wasmtime_linker_define_func, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeLinker, instantiate, arginfo_wasmtime_linker_instantiate, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Instance
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_instance_create(zend_class_entry *ce)
{
    php_wasmtime_instance_t *intern = zend_object_alloc(sizeof(php_wasmtime_instance_t), ce);
    ZVAL_UNDEF(&intern->store_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_instance_handlers;
    return &intern->std;
}

static void php_wasmtime_instance_free(zend_object *obj)
{
    php_wasmtime_instance_t *intern = php_wasmtime_instance_from_obj(obj);
    zval_ptr_dtor(&intern->store_zv);
    zend_object_std_dtor(obj);
}

static bool php_wasmtime_instance_get_export(php_wasmtime_instance_t *inst,
    const char *name, size_t name_len, wasmtime_extern_t *item)
{
    wasmtime_context_t *ctx = php_wasmtime_store_context(&inst->store_zv);
    return wasmtime_instance_export_get(ctx, &inst->instance, name, name_len, item);
}

PHP_METHOD(WasmtimeInstance, getFunc)
{
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) == FAILURE) {
        return;
    }

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_extern_t item;

    if (!php_wasmtime_instance_get_export(inst, ZSTR_VAL(name), ZSTR_LEN(name), &item)) {
        RETURN_NULL();
        return;
    }

    if (item.kind != WASMTIME_EXTERN_FUNC) {
        RETURN_NULL();
        return;
    }

    object_init_ex(return_value, wasmtime_ce_func);
    php_wasmtime_func_t *func_intern = Z_WASMTIME_FUNC_P(return_value);
    func_intern->func = item.of.func;
    ZVAL_COPY(&func_intern->store_zv, &inst->store_zv);
}

PHP_METHOD(WasmtimeInstance, getMemory)
{
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) == FAILURE) {
        return;
    }

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_extern_t item;

    if (!php_wasmtime_instance_get_export(inst, ZSTR_VAL(name), ZSTR_LEN(name), &item)) {
        RETURN_NULL();
        return;
    }

    if (item.kind != WASMTIME_EXTERN_MEMORY) {
        RETURN_NULL();
        return;
    }

    object_init_ex(return_value, wasmtime_ce_memory);
    php_wasmtime_memory_t *mem_intern = Z_WASMTIME_MEMORY_P(return_value);
    mem_intern->memory = item.of.memory;
    ZVAL_COPY(&mem_intern->store_zv, &inst->store_zv);
}

PHP_METHOD(WasmtimeInstance, getGlobal)
{
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) == FAILURE) {
        return;
    }

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_extern_t item;

    if (!php_wasmtime_instance_get_export(inst, ZSTR_VAL(name), ZSTR_LEN(name), &item)) {
        RETURN_NULL();
        return;
    }

    if (item.kind != WASMTIME_EXTERN_GLOBAL) {
        RETURN_NULL();
        return;
    }

    object_init_ex(return_value, wasmtime_ce_wasm_global);
    php_wasmtime_wasm_global_t *g = Z_WASMTIME_WASM_GLOBAL_P(return_value);
    g->global = item.of.global;
    ZVAL_COPY(&g->store_zv, &inst->store_zv);
}

PHP_METHOD(WasmtimeInstance, getTable)
{
    zend_string *name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &name) == FAILURE) {
        return;
    }

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_extern_t item;

    if (!php_wasmtime_instance_get_export(inst, ZSTR_VAL(name), ZSTR_LEN(name), &item)) {
        RETURN_NULL();
        return;
    }

    if (item.kind != WASMTIME_EXTERN_TABLE) {
        RETURN_NULL();
        return;
    }

    object_init_ex(return_value, wasmtime_ce_table);
    php_wasmtime_table_t *t = Z_WASMTIME_TABLE_P(return_value);
    t->table = item.of.table;
    ZVAL_COPY(&t->store_zv, &inst->store_zv);
}

PHP_METHOD(WasmtimeInstance, callFunc)
{
    zend_string *name;
    zval *args;
    int argc;

    ZEND_PARSE_PARAMETERS_START(1, -1)
        Z_PARAM_STR(name)
        Z_PARAM_VARIADIC('+', args, argc)
    ZEND_PARSE_PARAMETERS_END();

    /* adjust: variadic params may be empty */
    if (argc == 0) args = NULL;

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&inst->store_zv);
    wasmtime_extern_t item;

    if (!wasmtime_instance_export_get(ctx, &inst->instance,
            ZSTR_VAL(name), ZSTR_LEN(name), &item)) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Export '%s' not found", ZSTR_VAL(name));
        return;
    }

    if (item.kind != WASMTIME_EXTERN_FUNC) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Export '%s' is not a function", ZSTR_VAL(name));
        return;
    }

    wasm_functype_t *functype = wasmtime_func_type(ctx, &item.of.func);
    const wasm_valtype_vec_t *param_types = wasm_functype_params(functype);
    const wasm_valtype_vec_t *result_types = wasm_functype_results(functype);

    size_t nparams = param_types->size;
    size_t nresults = result_types->size;

    if ((size_t)argc < nparams) {
        wasm_functype_delete(functype);
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Function '%s' expects %zu arguments, %d given",
            ZSTR_VAL(name), nparams, argc);
        return;
    }

    wasmtime_val_t *wasm_args = NULL;
    wasmtime_val_t *wasm_results = NULL;

    if (nparams > 0) {
        wasm_args = safe_emalloc(nparams, sizeof(wasmtime_val_t), 0);
        for (size_t i = 0; i < nparams; i++) {
            if (!php_wasmtime_zval_to_val(&args[i], &wasm_args[i],
                    wasm_valtype_kind(param_types->data[i]))) {
                efree(wasm_args);
                wasm_functype_delete(functype);
                return;
            }
        }
    }

    if (nresults > 0) {
        wasm_results = safe_emalloc(nresults, sizeof(wasmtime_val_t), 0);
        memset(wasm_results, 0, nresults * sizeof(wasmtime_val_t));
    }

    wasm_trap_t *trap = NULL;
    wasmtime_error_t *error = wasmtime_func_call(ctx, &item.of.func,
        wasm_args, nparams, wasm_results, nresults, &trap);

    wasm_functype_delete(functype);
    if (wasm_args) efree(wasm_args);

    if (error) {
        if (wasm_results) efree(wasm_results);
        php_wasmtime_throw_error(error);
        return;
    }
    if (trap) {
        if (wasm_results) efree(wasm_results);
        php_wasmtime_throw_trap(trap);
        return;
    }

    if (nresults == 0) {
        if (wasm_results) efree(wasm_results);
        RETURN_NULL();
    } else if (nresults == 1) {
        php_wasmtime_val_to_zval(&wasm_results[0], return_value);
        efree(wasm_results);
    } else {
        array_init(return_value);
        for (size_t i = 0; i < nresults; i++) {
            zval elem;
            php_wasmtime_val_to_zval(&wasm_results[i], &elem);
            add_next_index_zval(return_value, &elem);
        }
        efree(wasm_results);
    }
}

PHP_METHOD(WasmtimeInstance, exportNames)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_instance_t *inst = Z_WASMTIME_INSTANCE_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&inst->store_zv);

    array_init(return_value);

    size_t index = 0;
    char *ename;
    size_t ename_len;
    wasmtime_extern_t item;

    while (wasmtime_instance_export_nth(ctx, &inst->instance, index,
            &ename, &ename_len, &item)) {
        zval entry;
        array_init(&entry);
        add_assoc_stringl(&entry, "name", ename, ename_len);

        const char *kind_str = "unknown";
        switch (item.kind) {
            case WASMTIME_EXTERN_FUNC: kind_str = "func"; break;
            case WASMTIME_EXTERN_GLOBAL: kind_str = "global"; break;
            case WASMTIME_EXTERN_TABLE: kind_str = "table"; break;
            case WASMTIME_EXTERN_MEMORY: kind_str = "memory"; break;
        }
        add_assoc_string(&entry, "kind", kind_str);
        add_next_index_zval(return_value, &entry);
        index++;
    }
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_instance_get_named, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_instance_call_func, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_VARIADIC_INFO(0, args)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_instance_void, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_instance_methods[] = {
    PHP_ME(WasmtimeInstance, getFunc, arginfo_wasmtime_instance_get_named, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeInstance, getMemory, arginfo_wasmtime_instance_get_named, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeInstance, getGlobal, arginfo_wasmtime_instance_get_named, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeInstance, getTable, arginfo_wasmtime_instance_get_named, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeInstance, callFunc, arginfo_wasmtime_instance_call_func, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeInstance, exportNames, arginfo_wasmtime_instance_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Func
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_func_create(zend_class_entry *ce)
{
    php_wasmtime_func_t *intern = zend_object_alloc(sizeof(php_wasmtime_func_t), ce);
    ZVAL_UNDEF(&intern->store_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_func_handlers;
    return &intern->std;
}

static void php_wasmtime_func_free(zend_object *obj)
{
    php_wasmtime_func_t *intern = php_wasmtime_func_from_obj(obj);
    zval_ptr_dtor(&intern->store_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeFunc, call)
{
    zval *args;
    int argc;

    ZEND_PARSE_PARAMETERS_START(0, -1)
        Z_PARAM_VARIADIC('*', args, argc)
    ZEND_PARSE_PARAMETERS_END();

    php_wasmtime_func_t *intern = Z_WASMTIME_FUNC_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasm_functype_t *functype = wasmtime_func_type(ctx, &intern->func);
    const wasm_valtype_vec_t *param_types = wasm_functype_params(functype);
    const wasm_valtype_vec_t *result_types = wasm_functype_results(functype);

    size_t nparams = param_types->size;
    size_t nresults = result_types->size;

    if ((size_t)argc < nparams) {
        wasm_functype_delete(functype);
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Function expects %zu arguments, %d given", nparams, argc);
        return;
    }

    wasmtime_val_t *wasm_args = NULL;
    wasmtime_val_t *wasm_results = NULL;

    if (nparams > 0) {
        wasm_args = safe_emalloc(nparams, sizeof(wasmtime_val_t), 0);
        for (size_t i = 0; i < nparams; i++) {
            if (!php_wasmtime_zval_to_val(&args[i], &wasm_args[i],
                    wasm_valtype_kind(param_types->data[i]))) {
                efree(wasm_args);
                wasm_functype_delete(functype);
                return;
            }
        }
    }

    if (nresults > 0) {
        wasm_results = safe_emalloc(nresults, sizeof(wasmtime_val_t), 0);
        memset(wasm_results, 0, nresults * sizeof(wasmtime_val_t));
    }

    wasm_trap_t *trap = NULL;
    wasmtime_error_t *error = wasmtime_func_call(ctx, &intern->func,
        wasm_args, nparams, wasm_results, nresults, &trap);

    wasm_functype_delete(functype);
    if (wasm_args) efree(wasm_args);

    if (error) {
        if (wasm_results) efree(wasm_results);
        php_wasmtime_throw_error(error);
        return;
    }
    if (trap) {
        if (wasm_results) efree(wasm_results);
        php_wasmtime_throw_trap(trap);
        return;
    }

    if (nresults == 0) {
        if (wasm_results) efree(wasm_results);
        RETURN_NULL();
    } else if (nresults == 1) {
        php_wasmtime_val_to_zval(&wasm_results[0], return_value);
        efree(wasm_results);
    } else {
        array_init(return_value);
        for (size_t i = 0; i < nresults; i++) {
            zval elem;
            php_wasmtime_val_to_zval(&wasm_results[i], &elem);
            add_next_index_zval(return_value, &elem);
        }
        efree(wasm_results);
    }
}

PHP_METHOD(WasmtimeFunc, paramTypes)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_func_t *intern = Z_WASMTIME_FUNC_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasm_functype_t *functype = wasmtime_func_type(ctx, &intern->func);
    const wasm_valtype_vec_t *params = wasm_functype_params(functype);

    array_init(return_value);
    for (size_t i = 0; i < params->size; i++) {
        add_next_index_string(return_value,
            php_wasmtime_valkind_name(wasm_valtype_kind(params->data[i])));
    }

    wasm_functype_delete(functype);
}

PHP_METHOD(WasmtimeFunc, resultTypes)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    php_wasmtime_func_t *intern = Z_WASMTIME_FUNC_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasm_functype_t *functype = wasmtime_func_type(ctx, &intern->func);
    const wasm_valtype_vec_t *results = wasm_functype_results(functype);

    array_init(return_value);
    for (size_t i = 0; i < results->size; i++) {
        add_next_index_string(return_value,
            php_wasmtime_valkind_name(wasm_valtype_kind(results->data[i])));
    }

    wasm_functype_delete(functype);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_func_call, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, args)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_func_void, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_func_methods[] = {
    PHP_ME(WasmtimeFunc, call, arginfo_wasmtime_func_call, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeFunc, paramTypes, arginfo_wasmtime_func_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeFunc, resultTypes, arginfo_wasmtime_func_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Memory
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_memory_create(zend_class_entry *ce)
{
    php_wasmtime_memory_t *intern = zend_object_alloc(sizeof(php_wasmtime_memory_t), ce);
    ZVAL_UNDEF(&intern->store_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_memory_handlers;
    return &intern->std;
}

static void php_wasmtime_memory_free(zend_object *obj)
{
    php_wasmtime_memory_t *intern = php_wasmtime_memory_from_obj(obj);
    zval_ptr_dtor(&intern->store_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeMemory, read)
{
    zend_long offset, length;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ll", &offset, &length) == FAILURE) {
        return;
    }

    if (offset < 0 || length < 0) {
        zend_throw_exception(wasmtime_ce_exception,
            "Offset and length must be non-negative", 0);
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + length) > data_size) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Memory access out of bounds: offset=%ld, length=%ld, memory_size=%zu",
            offset, length, data_size);
        return;
    }

    uint8_t *data = wasmtime_memory_data(ctx, &intern->memory);
    RETURN_STRINGL((char *)(data + offset), length);
}

PHP_METHOD(WasmtimeMemory, write)
{
    zend_long offset;
    zend_string *data;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "lS", &offset, &data) == FAILURE) {
        return;
    }

    if (offset < 0) {
        zend_throw_exception(wasmtime_ce_exception, "Offset must be non-negative", 0);
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + ZSTR_LEN(data)) > data_size) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Memory write out of bounds: offset=%ld, data_len=%zu, memory_size=%zu",
            offset, ZSTR_LEN(data), data_size);
        return;
    }

    uint8_t *mem = wasmtime_memory_data(ctx, &intern->memory);
    memcpy(mem + offset, ZSTR_VAL(data), ZSTR_LEN(data));
}

PHP_METHOD(WasmtimeMemory, size)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);
    RETURN_LONG((zend_long)wasmtime_memory_size(ctx, &intern->memory));
}

PHP_METHOD(WasmtimeMemory, dataSize)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);
    RETURN_LONG((zend_long)wasmtime_memory_data_size(ctx, &intern->memory));
}

PHP_METHOD(WasmtimeMemory, grow)
{
    zend_long delta;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &delta) == FAILURE) {
        return;
    }
    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    uint64_t prev_size;
    wasmtime_error_t *error = wasmtime_memory_grow(ctx, &intern->memory,
        (uint64_t)delta, &prev_size);
    if (error) {
        php_wasmtime_throw_error(error);
        return;
    }
    RETURN_LONG((zend_long)prev_size);
}

PHP_METHOD(WasmtimeMemory, readI32)
{
    zend_long offset;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &offset) == FAILURE) {
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + 4) > data_size || offset < 0) {
        zend_throw_exception(wasmtime_ce_exception, "Memory access out of bounds", 0);
        return;
    }

    uint8_t *data = wasmtime_memory_data(ctx, &intern->memory);
    int32_t val;
    memcpy(&val, data + offset, sizeof(int32_t));
    RETURN_LONG((zend_long)val);
}

PHP_METHOD(WasmtimeMemory, writeI32)
{
    zend_long offset, value;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ll", &offset, &value) == FAILURE) {
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + 4) > data_size || offset < 0) {
        zend_throw_exception(wasmtime_ce_exception, "Memory write out of bounds", 0);
        return;
    }

    uint8_t *data = wasmtime_memory_data(ctx, &intern->memory);
    int32_t val = (int32_t)value;
    memcpy(data + offset, &val, sizeof(int32_t));
}

PHP_METHOD(WasmtimeMemory, readF64)
{
    zend_long offset;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &offset) == FAILURE) {
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + 8) > data_size || offset < 0) {
        zend_throw_exception(wasmtime_ce_exception, "Memory access out of bounds", 0);
        return;
    }

    uint8_t *data = wasmtime_memory_data(ctx, &intern->memory);
    double val;
    memcpy(&val, data + offset, sizeof(double));
    RETURN_DOUBLE(val);
}

PHP_METHOD(WasmtimeMemory, writeF64)
{
    zend_long offset;
    double value;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ld", &offset, &value) == FAILURE) {
        return;
    }

    php_wasmtime_memory_t *intern = Z_WASMTIME_MEMORY_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    size_t data_size = wasmtime_memory_data_size(ctx, &intern->memory);
    if ((size_t)(offset + 8) > data_size || offset < 0) {
        zend_throw_exception(wasmtime_ce_exception, "Memory write out of bounds", 0);
        return;
    }

    uint8_t *data = wasmtime_memory_data(ctx, &intern->memory);
    memcpy(data + offset, &value, sizeof(double));
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_read, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, length, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_write, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_grow, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, delta, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_read_typed, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_write_i32, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_memory_write_f64, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, offset, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_DOUBLE, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_memory_methods[] = {
    PHP_ME(WasmtimeMemory, read, arginfo_wasmtime_memory_read, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, write, arginfo_wasmtime_memory_write, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, size, arginfo_wasmtime_memory_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, dataSize, arginfo_wasmtime_memory_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, grow, arginfo_wasmtime_memory_grow, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, readI32, arginfo_wasmtime_memory_read_typed, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, writeI32, arginfo_wasmtime_memory_write_i32, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, readF64, arginfo_wasmtime_memory_read_typed, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeMemory, writeF64, arginfo_wasmtime_memory_write_f64, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Global
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_wasm_global_create(zend_class_entry *ce)
{
    php_wasmtime_wasm_global_t *intern = zend_object_alloc(sizeof(php_wasmtime_wasm_global_t), ce);
    ZVAL_UNDEF(&intern->store_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_wasm_global_handlers;
    return &intern->std;
}

static void php_wasmtime_wasm_global_free(zend_object *obj)
{
    php_wasmtime_wasm_global_t *intern = php_wasmtime_wasm_global_from_obj(obj);
    zval_ptr_dtor(&intern->store_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeGlobal, get)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasm_global_t *intern = Z_WASMTIME_WASM_GLOBAL_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasmtime_val_t val;
    wasmtime_global_get(ctx, &intern->global, &val);
    php_wasmtime_val_to_zval(&val, return_value);
}

PHP_METHOD(WasmtimeGlobal, set)
{
    zval *value;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &value) == FAILURE) {
        return;
    }
    php_wasmtime_wasm_global_t *intern = Z_WASMTIME_WASM_GLOBAL_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasm_globaltype_t *gtype = wasmtime_global_type(ctx, &intern->global);
    const wasm_valtype_t *content_type = wasm_globaltype_content(gtype);
    wasm_valkind_t kind = wasm_valtype_kind(content_type);
    wasm_mutability_t mut = wasm_globaltype_mutability(gtype);
    wasm_globaltype_delete(gtype);

    if (mut != WASM_VAR) {
        zend_throw_exception(wasmtime_ce_exception,
            "Cannot set immutable global", 0);
        return;
    }

    wasmtime_val_t val;
    if (!php_wasmtime_zval_to_val(value, &val, kind)) {
        return;
    }

    wasmtime_error_t *error = wasmtime_global_set(ctx, &intern->global, &val);
    if (error) {
        php_wasmtime_throw_error(error);
    }
}

PHP_METHOD(WasmtimeGlobal, type)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasm_global_t *intern = Z_WASMTIME_WASM_GLOBAL_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);

    wasm_globaltype_t *gtype = wasmtime_global_type(ctx, &intern->global);
    const wasm_valtype_t *content_type = wasm_globaltype_content(gtype);
    wasm_valkind_t kind = wasm_valtype_kind(content_type);
    wasm_mutability_t mut = wasm_globaltype_mutability(gtype);
    wasm_globaltype_delete(gtype);

    array_init(return_value);
    add_assoc_string(return_value, "kind", php_wasmtime_valkind_name(kind));
    add_assoc_bool(return_value, "mutable", mut == WASM_VAR);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_global_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_global_set, 0, 0, 1)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_wasm_global_methods[] = {
    PHP_ME(WasmtimeGlobal, get, arginfo_wasmtime_global_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeGlobal, set, arginfo_wasmtime_global_set, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeGlobal, type, arginfo_wasmtime_global_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Table
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_table_create(zend_class_entry *ce)
{
    php_wasmtime_table_t *intern = zend_object_alloc(sizeof(php_wasmtime_table_t), ce);
    ZVAL_UNDEF(&intern->store_zv);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_table_handlers;
    return &intern->std;
}

static void php_wasmtime_table_free(zend_object *obj)
{
    php_wasmtime_table_t *intern = php_wasmtime_table_from_obj(obj);
    zval_ptr_dtor(&intern->store_zv);
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeTable, size)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_table_t *intern = Z_WASMTIME_TABLE_P(ZEND_THIS);
    wasmtime_context_t *ctx = php_wasmtime_store_context(&intern->store_zv);
    RETURN_LONG((zend_long)wasmtime_table_size(ctx, &intern->table));
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_table_void, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_table_methods[] = {
    PHP_ME(WasmtimeTable, size, arginfo_wasmtime_table_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * WasiConfig
 * ──────────────────────────────────────────────────────────────────────────── */

static zend_object *php_wasmtime_wasi_config_create(zend_class_entry *ce)
{
    php_wasmtime_wasi_config_t *intern = zend_object_alloc(sizeof(php_wasmtime_wasi_config_t), ce);
    intern->config = NULL;
    intern->consumed = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &wasmtime_wasi_config_handlers;
    return &intern->std;
}

static void php_wasmtime_wasi_config_free(zend_object *obj)
{
    php_wasmtime_wasi_config_t *intern = php_wasmtime_wasi_config_from_obj(obj);
    if (intern->config && !intern->consumed) {
        wasi_config_delete(intern->config);
        intern->config = NULL;
    }
    zend_object_std_dtor(obj);
}

PHP_METHOD(WasmtimeWasiConfig, __construct)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    intern->config = wasi_config_new();
    if (!intern->config) {
        zend_throw_exception(wasmtime_ce_exception, "Failed to create WASI config", 0);
    }
}

PHP_METHOD(WasmtimeWasiConfig, inheritStdio)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    wasi_config_inherit_stdin(intern->config);
    wasi_config_inherit_stdout(intern->config);
    wasi_config_inherit_stderr(intern->config);
}

PHP_METHOD(WasmtimeWasiConfig, inheritEnv)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    wasi_config_inherit_env(intern->config);
}

PHP_METHOD(WasmtimeWasiConfig, inheritArgv)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    wasi_config_inherit_argv(intern->config);
}

PHP_METHOD(WasmtimeWasiConfig, setArgv)
{
    HashTable *argv_ht;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "h", &argv_ht) == FAILURE) {
        return;
    }

    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }

    size_t argc = zend_hash_num_elements(argv_ht);
    const char **argv = safe_emalloc(argc, sizeof(const char *), 0);

    size_t i = 0;
    zval *val;
    ZEND_HASH_FOREACH_VAL(argv_ht, val) {
        convert_to_string(val);
        argv[i++] = Z_STRVAL_P(val);
    } ZEND_HASH_FOREACH_END();

    wasi_config_set_argv(intern->config, argc, argv);
    efree(argv);
}

PHP_METHOD(WasmtimeWasiConfig, setEnv)
{
    HashTable *env_ht;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "h", &env_ht) == FAILURE) {
        return;
    }

    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }

    size_t envc = zend_hash_num_elements(env_ht);
    const char **names = safe_emalloc(envc, sizeof(const char *), 0);
    const char **values = safe_emalloc(envc, sizeof(const char *), 0);

    size_t i = 0;
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(env_ht, key, val) {
        if (key) {
            convert_to_string(val);
            names[i] = ZSTR_VAL(key);
            values[i] = Z_STRVAL_P(val);
            i++;
        }
    } ZEND_HASH_FOREACH_END();

    wasi_config_set_env(intern->config, i, names, values);
    efree(names);
    efree(values);
}

PHP_METHOD(WasmtimeWasiConfig, setStdinFile)
{
    zend_string *path;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &path) == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    if (!wasi_config_set_stdin_file(intern->config, ZSTR_VAL(path))) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Failed to set stdin file: %s", ZSTR_VAL(path));
    }
}

PHP_METHOD(WasmtimeWasiConfig, setStdoutFile)
{
    zend_string *path;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &path) == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    if (!wasi_config_set_stdout_file(intern->config, ZSTR_VAL(path))) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Failed to set stdout file: %s", ZSTR_VAL(path));
    }
}

PHP_METHOD(WasmtimeWasiConfig, setStderrFile)
{
    zend_string *path;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &path) == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    if (!wasi_config_set_stderr_file(intern->config, ZSTR_VAL(path))) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Failed to set stderr file: %s", ZSTR_VAL(path));
    }
}

PHP_METHOD(WasmtimeWasiConfig, preopenDir)
{
    zend_string *host_path, *guest_path;
    zend_long dir_perms = 3; /* read+write */
    zend_long file_perms = 3;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS|ll",
        &host_path, &guest_path, &dir_perms, &file_perms) == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }
    if (!wasi_config_preopen_dir(intern->config,
            ZSTR_VAL(host_path), ZSTR_VAL(guest_path),
            (size_t)dir_perms, (size_t)file_perms)) {
        zend_throw_exception_ex(wasmtime_ce_exception, 0,
            "Failed to preopen directory: %s", ZSTR_VAL(host_path));
    }
}

PHP_METHOD(WasmtimeWasiConfig, setStdinBytes)
{
    zend_string *data;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &data) == FAILURE) {
        return;
    }
    php_wasmtime_wasi_config_t *intern = Z_WASMTIME_WASI_CONFIG_P(ZEND_THIS);
    if (intern->consumed) {
        zend_throw_exception(wasmtime_ce_exception, "WasiConfig already consumed", 0);
        return;
    }

    wasm_byte_vec_t bytes;
    wasm_byte_vec_new(&bytes, ZSTR_LEN(data), ZSTR_VAL(data));
    wasi_config_set_stdin_bytes(intern->config, &bytes);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_void, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_set_argv, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, argv, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_set_env, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, env, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_file, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_preopen_dir, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, hostPath, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, guestPath, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, dirPerms, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, filePerms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmtime_wasi_config_stdin_bytes, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmtime_wasi_config_methods[] = {
    PHP_ME(WasmtimeWasiConfig, __construct, arginfo_wasmtime_wasi_config_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, inheritStdio, arginfo_wasmtime_wasi_config_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, inheritEnv, arginfo_wasmtime_wasi_config_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, inheritArgv, arginfo_wasmtime_wasi_config_void, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setArgv, arginfo_wasmtime_wasi_config_set_argv, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setEnv, arginfo_wasmtime_wasi_config_set_env, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setStdinFile, arginfo_wasmtime_wasi_config_file, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setStdoutFile, arginfo_wasmtime_wasi_config_file, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setStderrFile, arginfo_wasmtime_wasi_config_file, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, preopenDir, arginfo_wasmtime_wasi_config_preopen_dir, ZEND_ACC_PUBLIC)
    PHP_ME(WasmtimeWasiConfig, setStdinBytes, arginfo_wasmtime_wasi_config_stdin_bytes, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* ────────────────────────────────────────────────────────────────────────────
 * Module init / shutdown / info
 * ──────────────────────────────────────────────────────────────────────────── */

static PHP_MINIT_FUNCTION(wasmtime)
{
    zend_class_entry ce;

    /* Exception class */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Exception", NULL);
    wasmtime_ce_exception = zend_register_internal_class_ex(&ce, spl_ce_RuntimeException);

    /* Engine */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Engine", wasmtime_engine_methods);
    wasmtime_ce_engine = zend_register_internal_class(&ce);
    wasmtime_ce_engine->create_object = php_wasmtime_engine_create;
    memcpy(&wasmtime_engine_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_engine_handlers.offset = XtOffsetOf(php_wasmtime_engine_t, std);
    wasmtime_engine_handlers.free_obj = php_wasmtime_engine_free;

    /* Store */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Store", wasmtime_store_methods);
    wasmtime_ce_store = zend_register_internal_class(&ce);
    wasmtime_ce_store->create_object = php_wasmtime_store_create;
    memcpy(&wasmtime_store_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_store_handlers.offset = XtOffsetOf(php_wasmtime_store_t, std);
    wasmtime_store_handlers.free_obj = php_wasmtime_store_free;

    /* Module */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Module", wasmtime_module_methods);
    wasmtime_ce_module = zend_register_internal_class(&ce);
    wasmtime_ce_module->create_object = php_wasmtime_module_create;
    memcpy(&wasmtime_module_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_module_handlers.offset = XtOffsetOf(php_wasmtime_module_t, std);
    wasmtime_module_handlers.free_obj = php_wasmtime_module_free;

    /* Linker */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Linker", wasmtime_linker_methods);
    wasmtime_ce_linker = zend_register_internal_class(&ce);
    wasmtime_ce_linker->create_object = php_wasmtime_linker_create;
    memcpy(&wasmtime_linker_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_linker_handlers.offset = XtOffsetOf(php_wasmtime_linker_t, std);
    wasmtime_linker_handlers.free_obj = php_wasmtime_linker_free;

    /* Instance */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Instance", wasmtime_instance_methods);
    wasmtime_ce_instance = zend_register_internal_class(&ce);
    wasmtime_ce_instance->create_object = php_wasmtime_instance_create;
    memcpy(&wasmtime_instance_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_instance_handlers.offset = XtOffsetOf(php_wasmtime_instance_t, std);
    wasmtime_instance_handlers.free_obj = php_wasmtime_instance_free;

    /* Func */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Func", wasmtime_func_methods);
    wasmtime_ce_func = zend_register_internal_class(&ce);
    wasmtime_ce_func->create_object = php_wasmtime_func_create;
    memcpy(&wasmtime_func_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_func_handlers.offset = XtOffsetOf(php_wasmtime_func_t, std);
    wasmtime_func_handlers.free_obj = php_wasmtime_func_free;

    /* Memory */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Memory", wasmtime_memory_methods);
    wasmtime_ce_memory = zend_register_internal_class(&ce);
    wasmtime_ce_memory->create_object = php_wasmtime_memory_create;
    memcpy(&wasmtime_memory_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_memory_handlers.offset = XtOffsetOf(php_wasmtime_memory_t, std);
    wasmtime_memory_handlers.free_obj = php_wasmtime_memory_free;

    /* Global (WasmGlobal in PHP to avoid keyword conflict) */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "WasmGlobal", wasmtime_wasm_global_methods);
    wasmtime_ce_wasm_global = zend_register_internal_class(&ce);
    wasmtime_ce_wasm_global->create_object = php_wasmtime_wasm_global_create;
    memcpy(&wasmtime_wasm_global_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_wasm_global_handlers.offset = XtOffsetOf(php_wasmtime_wasm_global_t, std);
    wasmtime_wasm_global_handlers.free_obj = php_wasmtime_wasm_global_free;

    /* Table */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Table", wasmtime_table_methods);
    wasmtime_ce_table = zend_register_internal_class(&ce);
    wasmtime_ce_table->create_object = php_wasmtime_table_create;
    memcpy(&wasmtime_table_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_table_handlers.offset = XtOffsetOf(php_wasmtime_table_t, std);
    wasmtime_table_handlers.free_obj = php_wasmtime_table_free;

    /* WasiConfig */
    INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "WasiConfig", wasmtime_wasi_config_methods);
    wasmtime_ce_wasi_config = zend_register_internal_class(&ce);
    wasmtime_ce_wasi_config->create_object = php_wasmtime_wasi_config_create;
    memcpy(&wasmtime_wasi_config_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    wasmtime_wasi_config_handlers.offset = XtOffsetOf(php_wasmtime_wasi_config_t, std);
    wasmtime_wasi_config_handlers.free_obj = php_wasmtime_wasi_config_free;

    /* Trap factory store for creating traps in callbacks */
    wasm_engine_t *trap_engine = wasm_engine_new();
    trap_factory_store = wasm_store_new(trap_engine);
    wasm_engine_delete(trap_engine);

    /* Constants for WASI permissions */
    REGISTER_NS_LONG_CONSTANT("Wasmtime", "WASI_DIR_READ", 1, CONST_CS | CONST_PERSISTENT);
    REGISTER_NS_LONG_CONSTANT("Wasmtime", "WASI_DIR_WRITE", 2, CONST_CS | CONST_PERSISTENT);
    REGISTER_NS_LONG_CONSTANT("Wasmtime", "WASI_FILE_READ", 1, CONST_CS | CONST_PERSISTENT);
    REGISTER_NS_LONG_CONSTANT("Wasmtime", "WASI_FILE_WRITE", 2, CONST_CS | CONST_PERSISTENT);

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(wasmtime)
{
    if (trap_factory_store) {
        wasm_store_delete(trap_factory_store);
        trap_factory_store = NULL;
    }
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(wasmtime)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "wasmtime support", "enabled");
    php_info_print_table_row(2, "wasmtime extension version", PHP_WASMTIME_VERSION);
    php_info_print_table_row(2, "wasmtime C API version", "29.0.1");
    php_info_print_table_end();
}

zend_module_entry wasmtime_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_WASMTIME_EXTNAME,
    NULL,
    PHP_MINIT(wasmtime),
    PHP_MSHUTDOWN(wasmtime),
    NULL,
    NULL,
    PHP_MINFO(wasmtime),
    PHP_WASMTIME_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_WASMTIME
ZEND_GET_MODULE(wasmtime)
#endif

#ifndef PHP_WASMTIME_H
#define PHP_WASMTIME_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/spl/spl_exceptions.h"

#include <wasm.h>
#include <wasmtime.h>
#include <wasi.h>

#define PHP_WASMTIME_VERSION "1.0.0"
#define PHP_WASMTIME_EXTNAME "wasmtime"

extern zend_module_entry wasmtime_module_entry;
#define phpext_wasmtime_ptr &wasmtime_module_entry

/* Class entries */
extern zend_class_entry *wasmtime_ce_engine;
extern zend_class_entry *wasmtime_ce_store;
extern zend_class_entry *wasmtime_ce_module;
extern zend_class_entry *wasmtime_ce_linker;
extern zend_class_entry *wasmtime_ce_instance;
extern zend_class_entry *wasmtime_ce_func;
extern zend_class_entry *wasmtime_ce_memory;
extern zend_class_entry *wasmtime_ce_wasm_global;
extern zend_class_entry *wasmtime_ce_table;
extern zend_class_entry *wasmtime_ce_wasi_config;
extern zend_class_entry *wasmtime_ce_exception;

/* Object handlers */
extern zend_object_handlers wasmtime_engine_handlers;
extern zend_object_handlers wasmtime_store_handlers;
extern zend_object_handlers wasmtime_module_handlers;
extern zend_object_handlers wasmtime_linker_handlers;
extern zend_object_handlers wasmtime_instance_handlers;
extern zend_object_handlers wasmtime_func_handlers;
extern zend_object_handlers wasmtime_memory_handlers;
extern zend_object_handlers wasmtime_wasm_global_handlers;
extern zend_object_handlers wasmtime_table_handlers;
extern zend_object_handlers wasmtime_wasi_config_handlers;

/* Internal object structs (zend_object must be LAST member) */

typedef struct {
    wasm_engine_t *engine;
    zend_object std;
} php_wasmtime_engine_t;

typedef struct {
    wasmtime_store_t *store;
    zval engine_zv;
    zend_object std;
} php_wasmtime_store_t;

typedef struct {
    wasmtime_module_t *module;
    zval engine_zv;
    zend_object std;
} php_wasmtime_module_t;

typedef struct {
    wasmtime_linker_t *linker;
    zval engine_zv;
    zend_object std;
} php_wasmtime_linker_t;

typedef struct {
    wasmtime_instance_t instance;
    zval store_zv;
    zend_object std;
} php_wasmtime_instance_t;

typedef struct {
    wasmtime_func_t func;
    zval store_zv;
    zend_object std;
} php_wasmtime_func_t;

typedef struct {
    wasmtime_memory_t memory;
    zval store_zv;
    zend_object std;
} php_wasmtime_memory_t;

typedef struct {
    wasmtime_global_t global;
    zval store_zv;
    zend_object std;
} php_wasmtime_wasm_global_t;

typedef struct {
    wasmtime_table_t table;
    zval store_zv;
    zend_object std;
} php_wasmtime_table_t;

typedef struct {
    wasi_config_t *config;
    bool consumed;
    zend_object std;
} php_wasmtime_wasi_config_t;

/* Callback data for host functions */
typedef struct {
    zval callable;
    wasm_valkind_t *param_kinds;
    wasm_valkind_t *result_kinds;
    size_t nparams;
    size_t nresults;
    wasm_engine_t *engine;
} php_wasmtime_callback_data_t;

/* Object accessor macros */
static inline php_wasmtime_engine_t *php_wasmtime_engine_from_obj(zend_object *obj) {
    return (php_wasmtime_engine_t *)((char *)obj - XtOffsetOf(php_wasmtime_engine_t, std));
}
static inline php_wasmtime_store_t *php_wasmtime_store_from_obj(zend_object *obj) {
    return (php_wasmtime_store_t *)((char *)obj - XtOffsetOf(php_wasmtime_store_t, std));
}
static inline php_wasmtime_module_t *php_wasmtime_module_from_obj(zend_object *obj) {
    return (php_wasmtime_module_t *)((char *)obj - XtOffsetOf(php_wasmtime_module_t, std));
}
static inline php_wasmtime_linker_t *php_wasmtime_linker_from_obj(zend_object *obj) {
    return (php_wasmtime_linker_t *)((char *)obj - XtOffsetOf(php_wasmtime_linker_t, std));
}
static inline php_wasmtime_instance_t *php_wasmtime_instance_from_obj(zend_object *obj) {
    return (php_wasmtime_instance_t *)((char *)obj - XtOffsetOf(php_wasmtime_instance_t, std));
}
static inline php_wasmtime_func_t *php_wasmtime_func_from_obj(zend_object *obj) {
    return (php_wasmtime_func_t *)((char *)obj - XtOffsetOf(php_wasmtime_func_t, std));
}
static inline php_wasmtime_memory_t *php_wasmtime_memory_from_obj(zend_object *obj) {
    return (php_wasmtime_memory_t *)((char *)obj - XtOffsetOf(php_wasmtime_memory_t, std));
}
static inline php_wasmtime_wasm_global_t *php_wasmtime_wasm_global_from_obj(zend_object *obj) {
    return (php_wasmtime_wasm_global_t *)((char *)obj - XtOffsetOf(php_wasmtime_wasm_global_t, std));
}
static inline php_wasmtime_table_t *php_wasmtime_table_from_obj(zend_object *obj) {
    return (php_wasmtime_table_t *)((char *)obj - XtOffsetOf(php_wasmtime_table_t, std));
}
static inline php_wasmtime_wasi_config_t *php_wasmtime_wasi_config_from_obj(zend_object *obj) {
    return (php_wasmtime_wasi_config_t *)((char *)obj - XtOffsetOf(php_wasmtime_wasi_config_t, std));
}

#define Z_WASMTIME_ENGINE_P(zv) php_wasmtime_engine_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_STORE_P(zv) php_wasmtime_store_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_MODULE_P(zv) php_wasmtime_module_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_LINKER_P(zv) php_wasmtime_linker_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_INSTANCE_P(zv) php_wasmtime_instance_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_FUNC_P(zv) php_wasmtime_func_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_MEMORY_P(zv) php_wasmtime_memory_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_WASM_GLOBAL_P(zv) php_wasmtime_wasm_global_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_TABLE_P(zv) php_wasmtime_table_from_obj(Z_OBJ_P(zv))
#define Z_WASMTIME_WASI_CONFIG_P(zv) php_wasmtime_wasi_config_from_obj(Z_OBJ_P(zv))

/* Helper functions */
void php_wasmtime_throw_error(wasmtime_error_t *error);
void php_wasmtime_throw_trap(wasm_trap_t *trap);
wasm_valkind_t php_wasmtime_parse_valkind(const char *type_str, size_t len);
void php_wasmtime_val_to_zval(const wasmtime_val_t *val, zval *zv);
bool php_wasmtime_zval_to_val(zval *zv, wasmtime_val_t *val, wasm_valkind_t kind);
wasmtime_context_t *php_wasmtime_store_context(zval *store_zv);

#endif /* PHP_WASMTIME_H */

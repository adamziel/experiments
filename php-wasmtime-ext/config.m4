PHP_ARG_ENABLE([wasmtime],
  [whether to enable wasmtime support],
  [AS_HELP_STRING([--enable-wasmtime],
    [Enable wasmtime support])],
  [no])

if test "$PHP_WASMTIME" != "no"; then
  WASMTIME_DIR="$srcdir/wasmtime-c-api"

  if test ! -d "$WASMTIME_DIR/include"; then
    AC_MSG_ERROR([wasmtime C API not found in wasmtime-c-api/. Download it first.])
  fi

  PHP_ADD_INCLUDE([$WASMTIME_DIR/include])
  PHP_ADD_LIBRARY_WITH_PATH([wasmtime], [$WASMTIME_DIR/lib], [WASMTIME_SHARED_LIBADD])

  dnl wasmtime requires these system libraries
  PHP_ADD_LIBRARY([m], 1, WASMTIME_SHARED_LIBADD)
  PHP_ADD_LIBRARY([dl], 1, WASMTIME_SHARED_LIBADD)
  PHP_ADD_LIBRARY([pthread], 1, WASMTIME_SHARED_LIBADD)

  PHP_SUBST(WASMTIME_SHARED_LIBADD)
  PHP_NEW_EXTENSION(wasmtime, php_wasmtime.c, $ext_shared)
fi

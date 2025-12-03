#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <stdlib.h>
#include "_cgo_export.h"

/*
 * arginfo:
 *  - echo(string name)
 *  - push(string payloadJSON)
 *  - wait(int ms)
 *  - stop()
 */

// echo(string name)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_echo, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// push(string payloadJSON)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_push, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

// wait(int ms)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_wait, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, milliseconds, IS_LONG, 0)
ZEND_END_ARG_INFO()

// stop()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_stop, 0, 0, 0)
ZEND_END_ARG_INFO()

/*
 * Реализации PHP-функций
 */

// PHP: SConcur\Extension\echo(string $name): string
PHP_FUNCTION(echo)
{
    char *name = NULL;
    size_t name_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name, &name_len) == FAILURE) {
        RETURN_THROWS();
    }

    // Go-функция echo (из //export echo)
    char *response = echo(name);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\push(string $payload): string
PHP_FUNCTION(push)
{
    char *payload = NULL;
    size_t payload_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &payload, &payload_len) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = push(payload);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\wait(int $ms): string
PHP_FUNCTION(wait)
{
    zend_long ms;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &ms) == FAILURE) {
        RETURN_THROWS();
    }

    // Go ждёт int64 (обычно мапится на long long)
    char *response = wait((long long)ms);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\stop(): void
PHP_FUNCTION(stop)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    stop();
    RETURN_NULL();
}

/*
 * Регистрация функций с неймспейсом SConcur\Extension
 */
static const zend_function_entry sconcur_functions[] = {
    ZEND_NS_FE("SConcur\\Extension", echo, arginfo_sconcur_echo)
    ZEND_NS_FE("SConcur\\Extension", push, arginfo_sconcur_push)
    ZEND_NS_FE("SConcur\\Extension", wait, arginfo_sconcur_wait)
    ZEND_NS_FE("SConcur\\Extension", stop, arginfo_sconcur_stop)
    PHP_FE_END
};

/*
 * Описание модуля
 */
zend_module_entry sconcur_module_entry = {
    STANDARD_MODULE_HEADER,
    "sconcur",
    sconcur_functions,
    NULL,  // MINIT
    NULL,  // MSHUTDOWN
    NULL,  // RINIT
    NULL,  // RSHUTDOWN
    NULL,  // MINFO
    "0.1",
    STANDARD_MODULE_PROPERTIES
};

/*
 * Точка входа модуля
 */
ZEND_GET_MODULE(sconcur)
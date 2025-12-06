#include <php.h>
#include <stdlib.h>
#include "_cgo_export.h"

#include <php.h>
#include <stdlib.h>
#include "_cgo_export.h"

/*
 * arginfo:
 *  - ping(string name)
 *  - push(int method, string taskKey, string payloadJSON)
 *  - wait(int ms)
 *  - count()
 *  - stop()
 */

// ping(string name)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_ping, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// push(int method, string taskKey, string payloadJSON)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_push, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, method, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

// wait(int ms)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_wait, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, milliseconds, IS_LONG, 0)
ZEND_END_ARG_INFO()

// stop()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_stop, 0, 0, 0)
ZEND_END_ARG_INFO()

// count()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_count, 0, 0, 0)
ZEND_END_ARG_INFO()

// cancel(string taskKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_cancel, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

/*
 * Реализации PHP-функций
 */

// PHP: SConcur\Extension\ping(string $name): string
PHP_FUNCTION(ping)
{
    char *name = NULL;
    size_t name_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name, &name_len) == FAILURE) {
        RETURN_THROWS();
    }

    // Go-функция ping (из //export ping)
    char *response = ping(name);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\push(int $method, string $taskKey, string $payload): string
PHP_FUNCTION(push)
{
    zend_long method;
    char *task_key = NULL, *payload = NULL;
    size_t task_key_len, payload_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "lss", &method, &task_key, &task_key_len, &payload, &payload_len) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = push((int)method, task_key, payload);

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

// PHP: SConcur\Extension\cancel(string $taskKey): void
PHP_FUNCTION(cancel)
{
    char *task_key = NULL;
    size_t task_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &task_key, &task_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    cancel(task_key);
    RETURN_NULL();
}

// PHP: SConcur\Extension\count(): int
PHP_FUNCTION(count)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    int result = count();
    RETURN_LONG(result);
}

/*
 * Регистрация функций с неймспейсом SConcur\Extension
 */
static const zend_function_entry sconcur_functions[] = {
    ZEND_NS_FE("SConcur\\Extension", ping, arginfo_sconcur_ping)
    ZEND_NS_FE("SConcur\\Extension", push, arginfo_sconcur_push)
    ZEND_NS_FE("SConcur\\Extension", wait, arginfo_sconcur_wait)
    ZEND_NS_FE("SConcur\\Extension", stop, arginfo_sconcur_stop)
    ZEND_NS_FE("SConcur\\Extension", cancel, arginfo_sconcur_cancel)
    ZEND_NS_FE("SConcur\\Extension", count, arginfo_sconcur_count)
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

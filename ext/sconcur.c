#include <php.h>
#include <stdlib.h>
#include "_cgo_export.h"

/*
 * arginfo:
 *  - ping(string name)
 *  - push(string flowKey, int method, string taskKey, string payloadJSON)
 *  - wait(string flowKey)
 *  - count()
 *  - stopFlow(string flowKey)
 *  - destroy()
 *  - version()
 */

// ping(string name)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_ping, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// push(string flowKey, int method, string taskKey, string payloadJSON)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_push, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

// wait(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_wait, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()


// count()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_count, 0, 0, 0)
ZEND_END_ARG_INFO()

// stopFlow(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_stopFlow, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// destroy()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_destroy, 0, 0, 0)
ZEND_END_ARG_INFO()

// version()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_version, 0, 0, 0)
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

// PHP: SConcur\Extension\push(string $flowKey, int $method, string $taskKey, string $payload): string
PHP_FUNCTION(push)
{
    zend_long method;
    char *flow_key = NULL, *task_key = NULL, *payload = NULL;
    size_t flow_key_len, task_key_len, payload_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "slss", &flow_key, &flow_key_len, &method, &task_key, &task_key_len, &payload, &payload_len) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = push(flow_key, (int)method, task_key, payload);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\wait(string $flowKey): string
PHP_FUNCTION(wait)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = wait(flow_key);

    RETVAL_STRING(response);
    free(response);
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

// PHP: SConcur\Extension\stopFlow(string $flowKey): void
PHP_FUNCTION(stopFlow)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    stopFlow(flow_key);
    RETURN_NULL();
}

// PHP: SConcur\Extension\destroy(): void
PHP_FUNCTION(destroy)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    destroy();
    RETURN_NULL();
}

// PHP: SConcur\Extension\version(): string
PHP_FUNCTION(version)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    char *response = version();

    RETVAL_STRING(response);
    free(response);
}

/*
 * Регистрация функций с неймспейсом SConcur\Extension
 */
static const zend_function_entry sconcur_functions[] = {
    ZEND_NS_FE("SConcur\\Extension", ping, arginfo_sconcur_ping)
    ZEND_NS_FE("SConcur\\Extension", push, arginfo_sconcur_push)
    ZEND_NS_FE("SConcur\\Extension", wait, arginfo_sconcur_wait)
    ZEND_NS_FE("SConcur\\Extension", count, arginfo_sconcur_count)
    ZEND_NS_FE("SConcur\\Extension", stopFlow, arginfo_sconcur_stopFlow)
    ZEND_NS_FE("SConcur\\Extension", destroy, arginfo_sconcur_destroy)
    ZEND_NS_FE("SConcur\\Extension", version, arginfo_sconcur_version)
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

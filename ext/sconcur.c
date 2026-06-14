#include <php.h>
#include <stdlib.h>
#include "_cgo_export.h"

/*
 * arginfo:
 *  - ping(string name)
 *  - push(string flowKey, int method, string taskKey, string payload)
 *  - next(string flowKey, string taskKey)
 *  - wait(string flowKey)
 *  - waitAny()
 *  - waitAnyTimeout(int timeoutMs)
 *  - tasksCount()
 *  - stopFlow(string flowKey)
 *  - httpStopAccepting(string flowKey)
 *  - destroy()
 *  - version()
 */

// ping(string name)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_ping, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// push(string flowKey, int method, string taskKey, string payload)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_push, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

// next(string flowKey, string taskKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_next, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// wait(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_wait, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// waitAny()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_waitAny, 0, 0, 0)
ZEND_END_ARG_INFO()

// waitAnyTimeout(int timeoutMs)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_waitAnyTimeout, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, timeoutMs, IS_LONG, 0)
ZEND_END_ARG_INFO()

// tasksCount()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_tasksCount, 0, 0, 0)
ZEND_END_ARG_INFO()

// stopFlow(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_stopFlow, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// httpStopAccepting(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_httpStopAccepting, 0, 0, 1)
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

    char *response = push(
        flow_key,
        (int)flow_key_len,
        (int)method,
        task_key,
        (int)task_key_len,
        payload,
        (int)payload_len
    );

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\next(string $flowKey, string $taskKey): string
PHP_FUNCTION(next)
{
    char *flow_key = NULL, *task_key = NULL;
    size_t flow_key_len, task_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ss", &flow_key, &flow_key_len, &task_key, &task_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = next(flow_key, task_key);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\wait(string $flowKey): string
PHP_FUNCTION(wait)
{
    char *flow_key = NULL;
    size_t flow_key_len;
    buffer_result_t response;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    response = wait(
        flow_key,
        (int)flow_key_len
    );

    if (response.err != NULL) {
        RETVAL_STRING(response.err);
        free(response.err);
        return;
    }

    RETVAL_STRINGL((char *)response.data, response.len);
    free(response.data);
}

// PHP: SConcur\Extension\waitAny(): string
PHP_FUNCTION(waitAny)
{
    buffer_result_t response;

    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    response = waitAny();

    if (response.err != NULL) {
        RETVAL_STRING(response.err);
        free(response.err);
        return;
    }

    RETVAL_STRINGL((char *)response.data, response.len);
    free(response.data);
}

// PHP: SConcur\Extension\waitAnyTimeout(int $timeoutMs): string
// Returns the literal "timeout" when no result became ready in time.
PHP_FUNCTION(waitAnyTimeout)
{
    zend_long timeout_ms;
    buffer_result_t response;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &timeout_ms) == FAILURE) {
        RETURN_THROWS();
    }

    response = waitAnyTimeout((int)timeout_ms);

    if (response.err != NULL) {
        RETVAL_STRING(response.err);
        free(response.err);
        return;
    }

    RETVAL_STRINGL((char *)response.data, response.len);
    free(response.data);
}

// PHP: SConcur\Extension\tasksCount(): int
PHP_FUNCTION(tasksCount)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    int result = tasksCount();
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

// PHP: SConcur\Extension\httpStopAccepting(string $flowKey): void
PHP_FUNCTION(httpStopAccepting)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    httpStopAccepting(flow_key);
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
    ZEND_NS_FE("SConcur\\Extension", next, arginfo_sconcur_next)
    ZEND_NS_FE("SConcur\\Extension", wait, arginfo_sconcur_wait)
    ZEND_NS_FE("SConcur\\Extension", waitAny, arginfo_sconcur_waitAny)
    ZEND_NS_FE("SConcur\\Extension", waitAnyTimeout, arginfo_sconcur_waitAnyTimeout)
    ZEND_NS_FE("SConcur\\Extension", tasksCount, arginfo_sconcur_tasksCount)
    ZEND_NS_FE("SConcur\\Extension", stopFlow, arginfo_sconcur_stopFlow)
    ZEND_NS_FE("SConcur\\Extension", httpStopAccepting, arginfo_sconcur_httpStopAccepting)
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
    "0.2.0",
    STANDARD_MODULE_PROPERTIES
};

/*
 * Точка входа модуля
 */
ZEND_GET_MODULE(sconcur)

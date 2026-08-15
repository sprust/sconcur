#include <php.h>
#include <stdlib.h>
#include <zend_atomic.h>
#include "_cgo_export.h"

/*
 * Preemption plumbing (see .ai/plans/coroutine-switcher.md, phase 2).
 *
 * A Go timer goroutine periodically requests a VM interrupt; on the next opcode
 * boundary the engine calls sconcur_interrupt_function on the PHP thread, which
 * invokes the PHP callback registered by armPreemption() (the Scheduler's
 * preempt hook that parks the current coroutine). The original interrupt
 * handler is always chained so pcntl signal dispatch keeps working.
 */

static void (*sconcur_original_interrupt_function)(zend_execute_data *execute_data) = NULL;

/* Written only on the PHP thread (armPreemption/disarmPreemption). */
static int sconcur_preemption_armed = 0;

static zval sconcur_preemption_callback;

/*
 * Called from the Go timer goroutine (a non-PHP thread): atomically requests a
 * VM interrupt on the single (NTS) PHP thread. The engine clears the flag and
 * calls zend_interrupt_function at the next opcode boundary.
 */
void sconcur_request_vm_interrupt(void)
{
    zend_atomic_bool_store(&EG(vm_interrupt), 1);
}

static void sconcur_preemption_release_callback(void)
{
    if (Z_TYPE(sconcur_preemption_callback) != IS_UNDEF) {
        zval_ptr_dtor(&sconcur_preemption_callback);
        ZVAL_UNDEF(&sconcur_preemption_callback);
    }
}

static void sconcur_interrupt_function(zend_execute_data *execute_data)
{
    /*
     * Never preempt while an autoload is in flight: the engine tracks the class
     * being loaded in EG(in_autoload), and parking the fiber mid-autoload makes
     * every other coroutine that asks for the same class fail with "class not
     * found" (the per-class in-progress guard blocks a second autoload attempt).
     */
    if (sconcur_preemption_armed
        && Z_TYPE(sconcur_preemption_callback) != IS_UNDEF
        && !EG(exception)
        && !zend_atomic_bool_load(&EG(timed_out))
        && (EG(in_autoload) == NULL || zend_hash_num_elements(EG(in_autoload)) == 0)
    ) {
        zval retval;

        ZVAL_UNDEF(&retval);

        if (call_user_function(NULL, NULL, &sconcur_preemption_callback, &retval, 0, NULL) == SUCCESS) {
            zval_ptr_dtor(&retval);
        }

        /*
         * An exception thrown by the callback (e.g. FlowStoppedException
         * unwinding a stopped coroutine) stays in EG(exception): the engine
         * propagates it at the interrupted opcode — the same semantics as a
         * throwing pcntl signal handler.
         */
    }

    if (sconcur_original_interrupt_function != NULL) {
        sconcur_original_interrupt_function(execute_data);
    }
}

/*
 * arginfo:
 *  - ping(string name)
 *  - push(string flowKey, string method, string taskKey, string payload, int ownerId)
 *  - next(string flowKey, string taskKey, int ownerId)
 *  - wait(string flowKey)
 *  - waitAny()
 *  - waitAnyTimeout(int timeoutMs)
 *  - tasksCount()
 *  - stopFlow(string flowKey)
 *  - httpStopAccepting(string flowKey)
 *  - socketStopAccepting(string flowKey)
 *  - wsStopAccepting(string flowKey)
 *  - destroy()
 *  - version()
 */

// ping(string name)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_ping, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// push(string flowKey, string method, string taskKey, string payload, int ownerId)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_push, 0, 0, 5)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, ownerId, IS_LONG, 0)
ZEND_END_ARG_INFO()

// next(string flowKey, string taskKey, int ownerId)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_next, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, taskKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, ownerId, IS_LONG, 0)
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

// waitAnyBatch(int maxResults)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_waitAnyBatch, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, maxResults, IS_LONG, 0)
ZEND_END_ARG_INFO()

// waitAnyTimeoutBatch(int timeoutMs, int maxResults)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_waitAnyTimeoutBatch, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, timeoutMs, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, maxResults, IS_LONG, 0)
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

// socketStopAccepting(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_socketStopAccepting, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// wsStopAccepting(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_wsStopAccepting, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// destroy()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_destroy, 0, 0, 0)
ZEND_END_ARG_INFO()

// version()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_version, 0, 0, 0)
ZEND_END_ARG_INFO()

// armPreemption(int quantumMs, callable callback)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_armPreemption, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, quantumMs, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, callback, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

// disarmPreemption()
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_disarmPreemption, 0, 0, 0)
ZEND_END_ARG_INFO()

/*
 * PHP function implementations
 */

// PHP: SConcur\Extension\ping(string $name): string
PHP_FUNCTION(ping)
{
    char *name = NULL;
    size_t name_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name, &name_len) == FAILURE) {
        RETURN_THROWS();
    }

    // The Go ping function (from //export ping)
    char *response = ping(name);

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\push(string $flowKey, string $method, string $taskKey, string $payload, int $ownerId): string
PHP_FUNCTION(push)
{
    char *flow_key = NULL, *method = NULL, *task_key = NULL, *payload = NULL;
    size_t flow_key_len, method_len, task_key_len, payload_len;
    zend_long owner_id = 0;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ssssl", &flow_key, &flow_key_len, &method, &method_len, &task_key, &task_key_len, &payload, &payload_len, &owner_id) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = push(
        flow_key,
        (int)flow_key_len,
        method,
        (int)method_len,
        task_key,
        (int)task_key_len,
        payload,
        (int)payload_len,
        (long long)owner_id
    );

    /* NULL means success (the hot path): return the interned empty string
     * instead of copying and freeing a malloc'ed one on every push. */
    if (response == NULL) {
        RETURN_EMPTY_STRING();
    }

    RETVAL_STRING(response);
    free(response);
}

// PHP: SConcur\Extension\next(string $flowKey, string $taskKey, int $ownerId): string
PHP_FUNCTION(next)
{
    char *flow_key = NULL, *task_key = NULL;
    size_t flow_key_len, task_key_len;
    zend_long owner_id = 0;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ssl", &flow_key, &flow_key_len, &task_key, &task_key_len, &owner_id) == FAILURE) {
        RETURN_THROWS();
    }

    char *response = next(flow_key, task_key, (long long)owner_id);

    /* NULL means success, like push. */
    if (response == NULL) {
        RETURN_EMPTY_STRING();
    }

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

// PHP: SConcur\Extension\waitAnyBatch(int $maxResults): string
// Returns a result multiframe: the first ready result (blocking, like waitAny)
// plus every already-ready result up to $maxResults in one crossing.
PHP_FUNCTION(waitAnyBatch)
{
    zend_long max_results;
    buffer_result_t response;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &max_results) == FAILURE) {
        RETURN_THROWS();
    }

    response = waitAnyBatch((int)max_results);

    if (response.err != NULL) {
        RETVAL_STRING(response.err);
        free(response.err);
        return;
    }

    RETVAL_STRINGL((char *)response.data, response.len);
    free(response.data);
}

// PHP: SConcur\Extension\waitAnyTimeoutBatch(int $timeoutMs, int $maxResults): string
// Returns the literal "timeout" when no result became ready in time.
PHP_FUNCTION(waitAnyTimeoutBatch)
{
    zend_long timeout_ms;
    zend_long max_results;
    buffer_result_t response;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ll", &timeout_ms, &max_results) == FAILURE) {
        RETURN_THROWS();
    }

    response = waitAnyTimeoutBatch((int)timeout_ms, (int)max_results);

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

    stopFlow(flow_key, (int)flow_key_len);
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

// PHP: SConcur\Extension\socketStopAccepting(string $flowKey): void
PHP_FUNCTION(socketStopAccepting)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    socketStopAccepting(flow_key);
    RETURN_NULL();
}

// PHP: SConcur\Extension\wsStopAccepting(string $flowKey): void
PHP_FUNCTION(wsStopAccepting)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    wsStopAccepting(flow_key);
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

// PHP: SConcur\Extension\armPreemption(int $quantumMs, callable $callback): void
// Registers the preempt callback and starts the Go-side interrupt timer. Re-arming
// replaces the previous timer and callback.
PHP_FUNCTION(armPreemption)
{
    zend_long quantum_ms;
    zval *callback;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "lz", &quantum_ms, &callback) == FAILURE) {
        RETURN_THROWS();
    }

    if (quantum_ms <= 0) {
        zend_throw_error(NULL, "armPreemption expects a positive quantumMs");
        RETURN_THROWS();
    }

    if (!zend_is_callable(callback, 0, NULL)) {
        zend_throw_error(NULL, "armPreemption expects a callable");
        RETURN_THROWS();
    }

    preemptionDisarm();
    sconcur_preemption_release_callback();

    ZVAL_COPY(&sconcur_preemption_callback, callback);

    sconcur_preemption_armed = 1;

    preemptionArm((int)quantum_ms);
}

// PHP: SConcur\Extension\disarmPreemption(): void
PHP_FUNCTION(disarmPreemption)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    preemptionDisarm();

    sconcur_preemption_armed = 0;

    sconcur_preemption_release_callback();
}

/*
 * Registering the functions under the SConcur\Extension namespace
 */
static const zend_function_entry sconcur_functions[] = {
    ZEND_NS_FE("SConcur\\Extension", ping, arginfo_sconcur_ping)
    ZEND_NS_FE("SConcur\\Extension", push, arginfo_sconcur_push)
    ZEND_NS_FE("SConcur\\Extension", next, arginfo_sconcur_next)
    ZEND_NS_FE("SConcur\\Extension", wait, arginfo_sconcur_wait)
    ZEND_NS_FE("SConcur\\Extension", waitAny, arginfo_sconcur_waitAny)
    ZEND_NS_FE("SConcur\\Extension", waitAnyTimeout, arginfo_sconcur_waitAnyTimeout)
    ZEND_NS_FE("SConcur\\Extension", waitAnyBatch, arginfo_sconcur_waitAnyBatch)
    ZEND_NS_FE("SConcur\\Extension", waitAnyTimeoutBatch, arginfo_sconcur_waitAnyTimeoutBatch)
    ZEND_NS_FE("SConcur\\Extension", tasksCount, arginfo_sconcur_tasksCount)
    ZEND_NS_FE("SConcur\\Extension", stopFlow, arginfo_sconcur_stopFlow)
    ZEND_NS_FE("SConcur\\Extension", httpStopAccepting, arginfo_sconcur_httpStopAccepting)
    ZEND_NS_FE("SConcur\\Extension", socketStopAccepting, arginfo_sconcur_socketStopAccepting)
    ZEND_NS_FE("SConcur\\Extension", wsStopAccepting, arginfo_sconcur_wsStopAccepting)
    ZEND_NS_FE("SConcur\\Extension", destroy, arginfo_sconcur_destroy)
    ZEND_NS_FE("SConcur\\Extension", version, arginfo_sconcur_version)
    ZEND_NS_FE("SConcur\\Extension", armPreemption, arginfo_sconcur_armPreemption)
    ZEND_NS_FE("SConcur\\Extension", disarmPreemption, arginfo_sconcur_disarmPreemption)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(sconcur)
{
    ZVAL_UNDEF(&sconcur_preemption_callback);

    sconcur_original_interrupt_function = zend_interrupt_function;
    zend_interrupt_function             = sconcur_interrupt_function;

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(sconcur)
{
    preemptionDisarm();

    sconcur_preemption_armed = 0;

    sconcur_preemption_release_callback();

    zend_interrupt_function = sconcur_original_interrupt_function;

    return SUCCESS;
}

/*
 * Module description
 */
zend_module_entry sconcur_module_entry = {
    STANDARD_MODULE_HEADER,
    "sconcur",
    sconcur_functions,
    PHP_MINIT(sconcur),
    PHP_MSHUTDOWN(sconcur),
    NULL,  // RINIT
    NULL,  // RSHUTDOWN
    NULL,  // MINFO
    "0.2.0",
    STANDARD_MODULE_PROPERTIES
};

/*
 * Module entry point
 */
ZEND_GET_MODULE(sconcur)

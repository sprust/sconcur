#include <php.h>
#include <stdlib.h>
#include <zend_atomic.h>
#include <zend_extensions.h>
#include <zend_fibers.h>
#include <zend_observer.h>
#include <zend_smart_str.h>
#include <ext/json/php_json.h>
#include <ext/pcre/php_pcre.h>
#include <ext/standard/basic_functions.h>
#include "sconcur_core.h"

/*
 * Preemption plumbing: what turns a CPU-bound handler into one that yields.
 *
 * A ticker task in the core's runtime periodically requests a VM interrupt; on
 * the next opcode boundary the engine calls sconcur_interrupt_function on the
 * PHP thread, which invokes the PHP callback registered by armPreemption() (the
 * Scheduler's preempt hook that parks the current coroutine). The original
 * interrupt handler is always chained so pcntl signal dispatch keeps working.
 */

static void (*sconcur_original_interrupt_function)(zend_execute_data *execute_data) = NULL;

/* Written only on the PHP thread (armPreemption/disarmPreemption). */
static int sconcur_preemption_armed = 0;

static zval sconcur_preemption_callback;

/*
 * Called from the core's preemption ticker (a non-PHP thread): atomically
 * requests a VM interrupt on the single (NTS) PHP thread. The engine clears the
 * flag and calls zend_interrupt_function at the next opcode boundary.
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
 * Per-coroutine engine state: what a neighbour must not see.
 *
 * PHP keeps the outcome of some calls in process-wide globals rather than in
 * the return value — json_last_error(), preg_last_error(), error_get_last() —
 * and serialize() keeps the object table its nested calls share there too. A
 * coroutine reads them the statement after the call and takes them for its
 * own, but a neighbour may have run in between: at any feature wait, and with
 * preemption armed between any two opcodes, where right after the call
 * returned is the likely spot. Nothing in PHP can set them back, so the
 * extension moves them. On every fiber switch the values of the fiber being
 * left are captured into a block hung on its context, and the values of the
 * fiber being entered are applied from its own block. A fiber that has never
 * run starts from a zeroed block, which is the clean state of each of them.
 *
 * Output buffers and the error handler stack are deliberately not here, see
 * docs/coroutine-switching.md. DateTime::getLastErrors() and the OpenSSL error
 * queue are not either: php does not export their globals, and reaching them
 * through the module registry the way libxml's are would mean freeing a
 * timelib container by hand for the one and chasing a ring buffer for the
 * other, for state nothing decides an outcome by.
 */

typedef struct {
    php_json_error_code          json_error_code;
    php_pcre_error_code          pcre_error_code;
    int                          last_error_type;
    int                          last_error_lineno;
    zend_string                 *last_error_message;
    zend_string                 *last_error_file;
    unsigned                     serialize_lock;
    struct php_serialize_data   *serialize_data;
    unsigned                     serialize_level;
    struct php_unserialize_data *unserialize_data;
    unsigned                     unserialize_level;
    smart_str                    libxml_error_buffer;
    zend_llist                  *libxml_error_list;
} sconcur_engine_state;

/*
 * One piece of state. capture moves the globals into the block and leaves them
 * reset; apply moves them back and leaves the block reset. A pointer is owned
 * by exactly one side at any moment, so nothing is refcounted on the way.
 * release drops what a block still owns when its fiber is destroyed. inherit
 * runs once, when a fiber is entered for the first time, with the block of the
 * context entering it: for state that is a setting as much as a result, the
 * new coroutine takes the setting over and starts with an empty result.
 */
typedef struct {
    void (*capture)(sconcur_engine_state *state);
    void (*apply)(sconcur_engine_state *state);
    void (*release)(sconcur_engine_state *state);
    void (*inherit)(sconcur_engine_state *state, const sconcur_engine_state *parent);
} sconcur_state_provider;

static void sconcur_json_capture(sconcur_engine_state *state)
{
    state->json_error_code = JSON_G(error_code);
    JSON_G(error_code)     = PHP_JSON_ERROR_NONE;
}

static void sconcur_json_apply(sconcur_engine_state *state)
{
    JSON_G(error_code)     = state->json_error_code;
    state->json_error_code = PHP_JSON_ERROR_NONE;
}

static void sconcur_pcre_capture(sconcur_engine_state *state)
{
    state->pcre_error_code = PCRE_G(error_code);
    PCRE_G(error_code)     = PHP_PCRE_NO_ERROR;
}

static void sconcur_pcre_apply(sconcur_engine_state *state)
{
    PCRE_G(error_code)     = state->pcre_error_code;
    state->pcre_error_code = PHP_PCRE_NO_ERROR;
}

static void sconcur_last_error_capture(sconcur_engine_state *state)
{
    state->last_error_type    = PG(last_error_type);
    state->last_error_lineno  = PG(last_error_lineno);
    state->last_error_message = PG(last_error_message);
    state->last_error_file    = PG(last_error_file);

    PG(last_error_type)    = 0;
    PG(last_error_lineno)  = 0;
    PG(last_error_message) = NULL;
    PG(last_error_file)    = NULL;
}

static void sconcur_last_error_apply(sconcur_engine_state *state)
{
    PG(last_error_type)    = state->last_error_type;
    PG(last_error_lineno)  = state->last_error_lineno;
    PG(last_error_message) = state->last_error_message;
    PG(last_error_file)    = state->last_error_file;

    state->last_error_type    = 0;
    state->last_error_lineno  = 0;
    state->last_error_message = NULL;
    state->last_error_file    = NULL;
}

static void sconcur_last_error_release(sconcur_engine_state *state)
{
    if (state->last_error_message != NULL) {
        zend_string_release(state->last_error_message);
        state->last_error_message = NULL;
    }

    if (state->last_error_file != NULL) {
        zend_string_release(state->last_error_file);
        state->last_error_file = NULL;
    }
}

/*
 * The table is created and freed by serialize()/unserialize() themselves; the
 * block only parks the pointer while the coroutine is suspended inside one of
 * them, so there is nothing to release. serialize_lock is the counter the
 * magic methods hold while they run, and a neighbour's count must not decide
 * whether this coroutine's call allocates a table or borrows one.
 */
static void sconcur_serialize_capture(sconcur_engine_state *state)
{
    state->serialize_lock    = BG(serialize_lock);
    state->serialize_data    = BG(serialize).data;
    state->serialize_level   = BG(serialize).level;
    state->unserialize_data  = BG(unserialize).data;
    state->unserialize_level = BG(unserialize).level;

    BG(serialize_lock)    = 0;
    BG(serialize).data    = NULL;
    BG(serialize).level   = 0;
    BG(unserialize).data  = NULL;
    BG(unserialize).level = 0;
}

static void sconcur_serialize_apply(sconcur_engine_state *state)
{
    BG(serialize_lock)    = state->serialize_lock;
    BG(serialize).data    = state->serialize_data;
    BG(serialize).level   = state->serialize_level;
    BG(unserialize).data  = state->unserialize_data;
    BG(unserialize).level = state->unserialize_level;

    state->serialize_lock    = 0;
    state->serialize_data    = NULL;
    state->serialize_level   = 0;
    state->unserialize_data  = NULL;
    state->unserialize_level = 0;
}

/*
 * libxml's error list is what libxml_use_internal_errors(true) turns on and
 * libxml_get_errors() reads; the buffer collects the pieces libxml hands over
 * before a line is complete. The list doubles as the switch: NULL means the
 * errors go out as warnings. php_libxml.h cannot be included without libxml2's
 * own headers, which the build images do not carry, so this is the prefix of
 * zend_libxml_globals as that header declares it — the three fields have led
 * the struct since PHP 5 — and the address comes from the module registry,
 * which is how the engine itself reaches a module's globals.
 */
typedef struct {
    zval        stream_context;
    smart_str   error_buffer;
    zend_llist *error_list;
} sconcur_libxml_globals_prefix;

static sconcur_libxml_globals_prefix *sconcur_libxml_globals = NULL;

static void sconcur_libxml_capture(sconcur_engine_state *state)
{
    if (sconcur_libxml_globals == NULL) {
        return;
    }

    state->libxml_error_buffer = sconcur_libxml_globals->error_buffer;
    state->libxml_error_list   = sconcur_libxml_globals->error_list;

    sconcur_libxml_globals->error_buffer.s = NULL;
    sconcur_libxml_globals->error_buffer.a = 0;
    sconcur_libxml_globals->error_list     = NULL;
}

static void sconcur_libxml_apply(sconcur_engine_state *state)
{
    if (sconcur_libxml_globals == NULL) {
        return;
    }

    sconcur_libxml_globals->error_buffer = state->libxml_error_buffer;
    sconcur_libxml_globals->error_list   = state->libxml_error_list;

    state->libxml_error_buffer.s = NULL;
    state->libxml_error_buffer.a = 0;
    state->libxml_error_list     = NULL;
}

static void sconcur_libxml_release(sconcur_engine_state *state)
{
    smart_str_free(&state->libxml_error_buffer);

    if (state->libxml_error_list != NULL) {
        zend_llist_destroy(state->libxml_error_list);
        efree(state->libxml_error_list);

        state->libxml_error_list = NULL;
    }
}

/*
 * A coroutine started where internal errors are on gets them on too, with a
 * list of its own: an application that enabled them at bootstrap would
 * otherwise see its parses warn again inside coroutines. The element dtor is
 * libxml's own, private to it, so it is taken from the parent's list.
 */
static void sconcur_libxml_inherit(sconcur_engine_state *state, const sconcur_engine_state *parent)
{
    if (parent->libxml_error_list == NULL || state->libxml_error_list != NULL) {
        return;
    }

    state->libxml_error_list = emalloc(sizeof(zend_llist));

    zend_llist_init(
        state->libxml_error_list,
        parent->libxml_error_list->size,
        parent->libxml_error_list->dtor,
        parent->libxml_error_list->persistent
    );
}

static const sconcur_state_provider sconcur_state_providers[] = {
    { sconcur_json_capture,       sconcur_json_apply,       NULL,                       NULL                   },
    { sconcur_pcre_capture,       sconcur_pcre_apply,       NULL,                       NULL                   },
    { sconcur_last_error_capture, sconcur_last_error_apply, sconcur_last_error_release, NULL                   },
    { sconcur_serialize_capture,  sconcur_serialize_apply,  NULL,                       NULL                   },
    { sconcur_libxml_capture,     sconcur_libxml_apply,     sconcur_libxml_release,     sconcur_libxml_inherit },
};

#define SCONCUR_STATE_PROVIDERS_COUNT (sizeof(sconcur_state_providers) / sizeof(sconcur_state_providers[0]))

/*
 * The index of this extension's slot in zend_fiber_context.reserved[], taken
 * once in MINIT; -1 when the engine had none left, in which case the state
 * stays process-wide and MINIT says so.
 */
static int sconcur_engine_state_handle = -1;

/*
 * The block of a fiber context, allocated on first sight. Fibers are created
 * zeroed, so a fresh one has no block yet and gets a zeroed one here; the main
 * context is created by the engine without any observer notification and gets
 * its block the same way, on the first switch it takes part in.
 */
static sconcur_engine_state *sconcur_engine_state_of(zend_fiber_context *context)
{
    sconcur_engine_state *state = context->reserved[sconcur_engine_state_handle];

    if (state == NULL) {
        state = ecalloc(1, sizeof(sconcur_engine_state));

        context->reserved[sconcur_engine_state_handle] = state;
    }

    return state;
}

static void sconcur_engine_state_free(zend_fiber_context *context)
{
    sconcur_engine_state *state = context->reserved[sconcur_engine_state_handle];

    if (state == NULL) {
        return;
    }

    for (size_t index = 0; index < SCONCUR_STATE_PROVIDERS_COUNT; index++) {
        if (sconcur_state_providers[index].release != NULL) {
            sconcur_state_providers[index].release(state);
        }
    }

    efree(state);

    context->reserved[sconcur_engine_state_handle] = NULL;
}

/*
 * Runs on the PHP thread for every fiber switch the engine makes — a feature
 * wait, Scheduler::switch(), preemption, a nested group, a Fiber the scheduler
 * never heard of — before the stacks are exchanged.
 */
static void sconcur_fiber_switch(zend_fiber_context *from, zend_fiber_context *to)
{
    bool                  first_entry = to->reserved[sconcur_engine_state_handle] == NULL;
    sconcur_engine_state *from_state  = sconcur_engine_state_of(from);
    sconcur_engine_state *to_state    = sconcur_engine_state_of(to);

    for (size_t index = 0; index < SCONCUR_STATE_PROVIDERS_COUNT; index++) {
        sconcur_state_providers[index].capture(from_state);
    }

    if (first_entry) {
        for (size_t index = 0; index < SCONCUR_STATE_PROVIDERS_COUNT; index++) {
            if (sconcur_state_providers[index].inherit != NULL) {
                sconcur_state_providers[index].inherit(to_state, from_state);
            }
        }
    }

    for (size_t index = 0; index < SCONCUR_STATE_PROVIDERS_COUNT; index++) {
        sconcur_state_providers[index].apply(to_state);
    }
}

static void sconcur_fiber_destroy(zend_fiber_context *context)
{
    sconcur_engine_state_free(context);
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
 *  - amqpStopConsuming(string flowKey)
 *  - amqpReopenConsumer(string flowKey, string channelId)
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

// amqpStopConsuming(string flowKey)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_amqpStopConsuming, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
ZEND_END_ARG_INFO()

// amqpReopenConsumer(string flowKey, string channelId)
ZEND_BEGIN_ARG_INFO_EX(arginfo_sconcur_amqpReopenConsumer, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, flowKey, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, channelId, IS_STRING, 0)
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

    // The core's ping (src/lib.rs), which mallocs the string this frees.
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

// PHP: SConcur\Extension\amqpStopConsuming(string $flowKey): void
PHP_FUNCTION(amqpStopConsuming)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    amqpStopConsuming(flow_key);
    RETURN_NULL();
}

// PHP: SConcur\Extension\amqpReopenConsumer(string $flowKey, string $channelId): void
PHP_FUNCTION(amqpReopenConsumer)
{
    char *flow_key = NULL;
    size_t flow_key_len;
    char *channel_id = NULL;
    size_t channel_id_len;

    if (zend_parse_parameters(
            ZEND_NUM_ARGS(), "ss",
            &flow_key, &flow_key_len,
            &channel_id, &channel_id_len
        ) == FAILURE) {
        RETURN_THROWS();
    }

    amqpReopenConsumer(flow_key, channel_id);
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
    ZEND_NS_FE("SConcur\\Extension", amqpStopConsuming, arginfo_sconcur_amqpStopConsuming)
    ZEND_NS_FE("SConcur\\Extension", amqpReopenConsumer, arginfo_sconcur_amqpReopenConsumer)
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

    /*
     * The fiber observers cannot be unregistered, so they are registered once
     * for the process and the slot index is what tells them where their block
     * is. The engine hands out six slots; running out of them is a warning, not
     * a refusal, since everything else here works without the isolation.
     */
    sconcur_engine_state_handle = zend_get_resource_handle("sconcur");

    if (sconcur_engine_state_handle >= 0) {
        /*
         * libxml is built in or loaded before this extension; absent, its
         * provider stays a no-op and the rest is unaffected.
         */
        zend_module_entry *libxml_module = zend_hash_str_find_ptr(&module_registry, "libxml", sizeof("libxml") - 1);

        if (libxml_module != NULL && libxml_module->globals_ptr != NULL) {
            sconcur_libxml_globals = libxml_module->globals_ptr;
        }

        zend_observer_fiber_switch_register(sconcur_fiber_switch);
        zend_observer_fiber_destroy_register(sconcur_fiber_destroy);
    } else {
        zend_error(E_CORE_WARNING, "sconcur: no fiber slot left for per-coroutine engine state; json_last_error(), preg_last_error(), error_get_last(), libxml errors and serialize() stay process-wide");
    }

    return SUCCESS;
}

/*
 * The main context is freed by the engine without a destroy notification, and
 * it is still alive here — shutdown_executor frees it after the modules have
 * shut their requests down. Its block holds nothing at this point (the main
 * context is the one running), so this is the memory alone.
 */
PHP_RSHUTDOWN_FUNCTION(sconcur)
{
    if (sconcur_engine_state_handle >= 0 && EG(main_fiber_context) != NULL) {
        sconcur_engine_state_free(EG(main_fiber_context));
    }

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
 *
 * The version field is left empty here and filled in below from the core, so
 * this file is not a fifth place a release has to bump (the four are listed in
 * .ai/README.md). NO_VERSION_YET is what the engine expects when a module
 * reports none; anything read before get_module() runs sees that rather than a
 * stale literal.
 */
zend_module_entry sconcur_module_entry = {
    STANDARD_MODULE_HEADER,
    "sconcur",
    sconcur_functions,
    PHP_MINIT(sconcur),
    PHP_MSHUTDOWN(sconcur),
    NULL,  // RINIT
    PHP_RSHUTDOWN(sconcur),
    NULL,  // MINFO
    NO_VERSION_YET,
    STANDARD_MODULE_PROPERTIES
};

/*
 * Module entry point
 *
 * ZEND_GET_MODULE's own body is just the return; the assignment before it takes
 * the version the core reports (ext/src/lib.rs) and hands it to phpinfo(),
 * `php --ri sconcur` and phpversion('sconcur'), which would otherwise disagree
 * with SConcur\Extension\version() and with the package's
 * REQUIRED_EXTENSION_VERSION.
 *
 * The string is malloc'ed by the core and deliberately never freed: the engine
 * reads the field for as long as the module is loaded, and this runs once per
 * process. A failed allocation answers NULL, which is NO_VERSION_YET again.
 */
BEGIN_EXTERN_C()
ZEND_DLEXPORT zend_module_entry *get_module(void)
{
    if (sconcur_module_entry.version == NO_VERSION_YET) {
        sconcur_module_entry.version = version();
    }

    return &sconcur_module_entry;
}
END_EXTERN_C()

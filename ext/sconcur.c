#include <php.h>
#include <stdlib.h>
#include <zend_atomic.h>
#include <zend_enum.h>
#include <zend_exceptions.h>
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
 * Result builder: what turns the fields of a finished task into the PHP
 * package's TaskResultDto, on the PHP thread, inside a wait export.
 *
 * The core hands over one sconcur_result_t (sconcur_core.h) per result, and this
 * side instantiates SConcur\Dto\TaskResultDto for it directly: the class entry is
 * looked up once, through the autoloader like any class, the offset of every
 * declared property is read from it, and each result becomes a new object whose
 * property slots are written in place. No constructor call and no name lookup
 * per property, the way unserialize() and ext-msgpack materialize an object.
 * That makes the property list of TaskResultDto a wire contract with this file,
 * and its docblock says so.
 *
 * The cache lives for one request. Under PHP-FPM userland classes are declared
 * anew for every request, so the previous request's class entry is a dangling
 * pointer: RSHUTDOWN clears the cache. The CLI's single request never sees the
 * reset.
 */

static const char *const SCONCUR_RESULT_CLASS    = "SConcur\\Dto\\TaskResultDto";
static const char *const SCONCUR_METHOD_CLASS    = "SConcur\\Features\\MethodEnum";
static const char *const SCONCUR_FORMAT_EXCEPTION = "SConcur\\Exceptions\\UnexpectedResponseFormatException";

enum {
    SCONCUR_SLOT_FLOW_KEY,
    SCONCUR_SLOT_METHOD,
    SCONCUR_SLOT_KEY,
    SCONCUR_SLOT_IS_ERROR,
    SCONCUR_SLOT_PAYLOAD,
    SCONCUR_SLOT_HAS_NEXT,
    SCONCUR_SLOT_EXECUTION_MS,
    SCONCUR_SLOT_TOTAL_EXECUTION_MS,
    SCONCUR_SLOT_OWNER_FIBER_ID,
    SCONCUR_SLOT_COUNT
};

/* The declared properties of TaskResultDto, in slot order. */
static const char *const SCONCUR_RESULT_PROPERTIES[SCONCUR_SLOT_COUNT] = {
    "flowKey",
    "method",
    "key",
    "isError",
    "payload",
    "hasNext",
    "executionMs",
    "totalExecutionMs",
    "ownerFiberId",
};

static zend_class_entry *sconcur_result_ce = NULL;
static zend_class_entry *sconcur_method_ce = NULL;
static uint32_t sconcur_result_slots[SCONCUR_SLOT_COUNT];

static zend_class_entry *sconcur_lookup_class(const char *name)
{
    zend_string *class_name = zend_string_init(name, strlen(name), 0);
    zend_class_entry *ce = zend_lookup_class(class_name);

    zend_string_release(class_name);

    return ce;
}

void sconcur_throw(const char *class_name, const char *message)
{
    zend_class_entry *ce = sconcur_lookup_class(class_name);

    /* The autoloader threw: that exception is the report, not this one. */
    if (EG(exception) != NULL) {
        return;
    }

    if (ce == NULL) {
        zend_throw_error(NULL, "%s", message);
        return;
    }

    zend_throw_exception(ce, message, 0);
}

static void sconcur_result_cache_reset(void)
{
    sconcur_result_ce = NULL;
    sconcur_method_ce = NULL;
}

/*
 * Resolves the two classes and the property slots on first use. Answers 0 with
 * an exception pending when it cannot; nothing is cached until all of it could
 * be, so a failed attempt is retried whole.
 */
static int sconcur_result_classes_ready(void)
{
    zend_class_entry *result_ce;
    zend_class_entry *method_ce;
    uint32_t slots[SCONCUR_SLOT_COUNT];

    if (sconcur_result_ce != NULL) {
        return 1;
    }

    result_ce = sconcur_lookup_class(SCONCUR_RESULT_CLASS);

    if (result_ce == NULL) {
        if (EG(exception) == NULL) {
            zend_throw_error(NULL, "The class %s cannot be loaded, so no task result can be built", SCONCUR_RESULT_CLASS);
        }

        return 0;
    }

    method_ce = sconcur_lookup_class(SCONCUR_METHOD_CLASS);

    if (method_ce == NULL) {
        if (EG(exception) == NULL) {
            zend_throw_error(NULL, "The enum %s cannot be loaded, so no task result can be built", SCONCUR_METHOD_CLASS);
        }

        return 0;
    }

    for (int index = 0; index < SCONCUR_SLOT_COUNT; index++) {
        const char *name = SCONCUR_RESULT_PROPERTIES[index];
        zend_property_info *info = zend_hash_str_find_ptr(&result_ce->properties_info, name, strlen(name));

        if (info == NULL || (info->flags & ZEND_ACC_STATIC) != 0) {
            zend_throw_error(NULL, "%s declares no property %s, which the result builder writes", SCONCUR_RESULT_CLASS, name);
            return 0;
        }

        slots[index] = info->offset;
    }

    memcpy(sconcur_result_slots, slots, sizeof slots);

    sconcur_method_ce = method_ce;
    /* Last: a non-NULL result class is what marks the cache as filled. */
    sconcur_result_ce = result_ce;

    return 1;
}

/*
 * The declared-property slot at offset, ready for its first write: a new
 * object's slot is uninitialized and flagged so, and the flags are cleared the
 * way zend_std_write_property clears them on that first write. The caller
 * writes the value straight into the slot, which then owns the reference.
 */
static zval *sconcur_slot(zend_object *object, uint32_t offset)
{
    zval *slot = OBJ_PROP(object, offset);

    Z_PROP_FLAG_P(slot) &= ~(IS_PROP_UNINIT | IS_PROP_REINITABLE);

    return slot;
}

static void sconcur_slot_stringl(zend_object *object, uint32_t offset, const char *bytes, size_t length)
{
    /* FAST: an empty or one-byte string is interned, and the payload of the
     * cheapest results is empty. */
    ZVAL_STRINGL_FAST(sconcur_slot(object, offset), bytes, length);
}

static void sconcur_slot_bool(zend_object *object, uint32_t offset, int flag)
{
    ZVAL_BOOL(sconcur_slot(object, offset), flag != 0);
}

static void sconcur_slot_long(zend_object *object, uint32_t offset, zend_long number)
{
    ZVAL_LONG(sconcur_slot(object, offset), number);
}

zval *sconcur_result_into(zval *destination, const sconcur_result_t *result)
{
    zend_object *method_case;
    zend_object *object;

    if (!sconcur_result_classes_ready()) {
        return NULL;
    }

    method_case = zend_enum_get_case_cstr(sconcur_method_ce, result->method_case);

    if (method_case == NULL) {
        /*
         * Reported rather than folded into MethodEnum::Unknown. A method this
         * package does not know is the version skew REQUIRED_EXTENSION_VERSION
         * exists to catch, and building a valid result out of it is how that
         * signal would be lost at the one point it could be seen. The core's own
         * unknown method is not this case: it names the Unknown case and resolves
         * here like any other.
         */
        char message[256];

        snprintf(
            message,
            sizeof message,
            "The core reports a result of the method '%s', which this package does not know: the loaded core is newer than the package.",
            result->method_case
        );
        sconcur_throw(SCONCUR_FORMAT_EXCEPTION, message);

        return NULL;
    }

    if (object_init_ex(destination, sconcur_result_ce) == FAILURE) {
        return NULL;
    }

    object = Z_OBJ_P(destination);

    sconcur_slot_stringl(object, sconcur_result_slots[SCONCUR_SLOT_FLOW_KEY], result->flow_key, result->flow_key_len);
    /* The enum case is shared, so the slot takes a new reference to it. */
    ZVAL_OBJ_COPY(sconcur_slot(object, sconcur_result_slots[SCONCUR_SLOT_METHOD]), method_case);
    sconcur_slot_stringl(object, sconcur_result_slots[SCONCUR_SLOT_KEY], result->task_key, result->task_key_len);
    sconcur_slot_bool(object, sconcur_result_slots[SCONCUR_SLOT_IS_ERROR], result->is_error);
    sconcur_slot_bool(object, sconcur_result_slots[SCONCUR_SLOT_HAS_NEXT], result->has_next);
    sconcur_slot_long(object, sconcur_result_slots[SCONCUR_SLOT_EXECUTION_MS], (zend_long)result->execution_ms);
    sconcur_slot_long(object, sconcur_result_slots[SCONCUR_SLOT_TOTAL_EXECUTION_MS], (zend_long)result->total_execution_ms);
    sconcur_slot_long(object, sconcur_result_slots[SCONCUR_SLOT_OWNER_FIBER_ID], (zend_long)result->owner_id);

    /* Null until the core fills it, so a failure halfway leaves a whole object. */
    zval *payload = sconcur_slot(object, sconcur_result_slots[SCONCUR_SLOT_PAYLOAD]);

    ZVAL_NULL(payload);

    return payload;
}

zval *sconcur_result_append(zval *list, const sconcur_result_t *result)
{
    zval element;
    zval *payload;
    zval *stored;

    ZVAL_UNDEF(&element);

    payload = sconcur_result_into(&element, result);

    if (payload == NULL) {
        zval_ptr_dtor(&element);
        return NULL;
    }

    /* The list takes over the element's reference; the payload slot lives in the
     * object, which does not move, so the pointer stays valid. */
    stored = zend_hash_next_index_insert(Z_ARRVAL_P(list), &element);

    if (stored == NULL) {
        zval_ptr_dtor(&element);
        zend_throw_error(NULL, "A task result could not be appended to the batch");
        return NULL;
    }

    return payload;
}

/*
 * Value builder: the zval of each node of a result's value tree, written where
 * the core says. A container is initialized to its final size, then each child
 * gets a slot: a null placeholder inserted into the array (or the declared
 * property of the object) that the core overwrites in place. The slot of one
 * child is handed out only after the previous one is complete, so a growing
 * table never moves a slot the core still holds.
 */

void sconcur_value_null(zval *destination)
{
    ZVAL_NULL(destination);
}

void sconcur_value_bool(zval *destination, int flag)
{
    ZVAL_BOOL(destination, flag != 0);
}

void sconcur_value_long(zval *destination, long long number)
{
    ZVAL_LONG(destination, (zend_long)number);
}

void sconcur_value_double(zval *destination, double number)
{
    ZVAL_DOUBLE(destination, number);
}

void sconcur_value_stringl(zval *destination, const char *bytes, size_t length)
{
    ZVAL_STRINGL_FAST(destination, bytes, length);
}

void sconcur_value_array(zval *destination, unsigned int size)
{
    array_init_size(destination, size);
}

zval *sconcur_array_next_slot(zval *array)
{
    zval placeholder;
    zval *slot;

    ZVAL_NULL(&placeholder);

    slot = zend_hash_next_index_insert(Z_ARRVAL_P(array), &placeholder);

    if (slot == NULL) {
        zend_throw_error(NULL, "A list element could not be appended: the array is full");
    }

    return slot;
}

zval *sconcur_array_index_slot(zval *array, long long index)
{
    zval placeholder;

    ZVAL_NULL(&placeholder);

    return zend_hash_index_update(Z_ARRVAL_P(array), (zend_ulong)index, &placeholder);
}

zval *sconcur_array_key_slot(zval *array, const char *key, size_t length)
{
    zval placeholder;

    ZVAL_NULL(&placeholder);

    /* symtable: a numeric-string key becomes the integer key PHP would make
     * of it in an array literal. */
    return zend_symtable_str_update(Z_ARRVAL_P(array), key, length, &placeholder);
}

/*
 * The classes a value tree names, cached per request like the result class:
 * a handful of value classes (the BSON ones), looked up through the
 * autoloader once and reused for the rest of the request.
 */
#define SCONCUR_VALUE_CLASS_CACHE_SIZE 16

typedef struct {
    char *name;
    size_t length;
    zend_class_entry *ce;
} sconcur_value_class_t;

static sconcur_value_class_t sconcur_value_classes[SCONCUR_VALUE_CLASS_CACHE_SIZE];
static int sconcur_value_class_count = 0;

static void sconcur_value_class_cache_reset(void)
{
    for (int index = 0; index < sconcur_value_class_count; index++) {
        free(sconcur_value_classes[index].name);
    }

    sconcur_value_class_count = 0;
}

static zend_class_entry *sconcur_value_class(const char *name, size_t length)
{
    zend_string *class_name;
    zend_class_entry *ce;

    for (int index = 0; index < sconcur_value_class_count; index++) {
        sconcur_value_class_t *cached = &sconcur_value_classes[index];

        if (cached->length == length && memcmp(cached->name, name, length) == 0) {
            return cached->ce;
        }
    }

    class_name = zend_string_init(name, length, 0);
    ce = zend_lookup_class(class_name);
    zend_string_release(class_name);

    if (ce == NULL) {
        if (EG(exception) == NULL) {
            zend_throw_error(NULL, "The class %.*s a task result carries cannot be loaded", (int)length, name);
        }

        return NULL;
    }

    if (sconcur_value_class_count < SCONCUR_VALUE_CLASS_CACHE_SIZE) {
        sconcur_value_class_t *cached = &sconcur_value_classes[sconcur_value_class_count];

        cached->name = malloc(length);

        if (cached->name != NULL) {
            memcpy(cached->name, name, length);
            cached->length = length;
            cached->ce = ce;
            sconcur_value_class_count++;
        }
    }

    return ce;
}

int sconcur_value_object(zval *destination, const char *class_name, size_t length)
{
    zend_class_entry *ce = sconcur_value_class(class_name, length);

    if (ce == NULL) {
        return 0;
    }

    return object_init_ex(destination, ce) == SUCCESS;
}

zval *sconcur_object_property_slot(zval *object, const char *name, size_t length)
{
    zend_object *instance = Z_OBJ_P(object);
    zend_property_info *info = zend_hash_str_find_ptr(&instance->ce->properties_info, name, length);
    zval *slot;

    if (info == NULL || (info->flags & ZEND_ACC_STATIC) != 0) {
        zend_throw_error(
            NULL,
            "%s declares no property %.*s, which a task result writes",
            ZSTR_VAL(instance->ce->name),
            (int)length,
            name
        );

        return NULL;
    }

    slot = sconcur_slot(instance, info->offset);

    ZVAL_NULL(slot);

    return slot;
}

/*
 * Finishes a wait export. READY leaves the value the core wrote in place;
 * TIMEOUT answers null; FAILED releases whatever was built before the failure
 * and lets the exception the core threw propagate, with a backstop for the
 * case it threw none.
 */
static void sconcur_finish_wait(zval *return_value, int status, const char *what)
{
    if (status == SCONCUR_WAIT_READY) {
        return;
    }

    zval_ptr_dtor(return_value);
    ZVAL_NULL(return_value);

    if (status == SCONCUR_WAIT_TIMEOUT) {
        return;
    }

    if (EG(exception) == NULL) {
        zend_throw_error(NULL, "%s failed without a report", what);
    }
}

/*
 * arginfo:
 *  - ping(string name)
 *  - push(string flowKey, string method, string taskKey, string payload, int ownerId)
 *  - next(string flowKey, string taskKey, int ownerId)
 *  - wait(string flowKey): TaskResultDto
 *  - waitAny(): TaskResultDto
 *  - waitAnyTimeout(int timeoutMs): ?TaskResultDto
 *  - waitAnyBatch(int maxResults): list<TaskResultDto>
 *  - waitAnyTimeoutBatch(int timeoutMs, int maxResults): ?list<TaskResultDto>
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

// PHP: SConcur\Extension\wait(string $flowKey): TaskResultDto
PHP_FUNCTION(wait)
{
    char *flow_key = NULL;
    size_t flow_key_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &flow_key, &flow_key_len) == FAILURE) {
        RETURN_THROWS();
    }

    sconcur_finish_wait(return_value, wait(flow_key, (int)flow_key_len, return_value), "wait");
}

// PHP: SConcur\Extension\waitAny(): TaskResultDto
PHP_FUNCTION(waitAny)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    sconcur_finish_wait(return_value, waitAny(return_value), "waitAny");
}

// PHP: SConcur\Extension\waitAnyTimeout(int $timeoutMs): ?TaskResultDto
// Null when no result became ready in time.
PHP_FUNCTION(waitAnyTimeout)
{
    zend_long timeout_ms;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &timeout_ms) == FAILURE) {
        RETURN_THROWS();
    }

    sconcur_finish_wait(return_value, waitAnyTimeout((int)timeout_ms, return_value), "waitAnyTimeout");
}

// PHP: SConcur\Extension\waitAnyBatch(int $maxResults): list<TaskResultDto>
// The first ready result (blocking, like waitAny) plus every already-ready
// result up to $maxResults, in one crossing.
PHP_FUNCTION(waitAnyBatch)
{
    zend_long max_results;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &max_results) == FAILURE) {
        RETURN_THROWS();
    }

    sconcur_finish_wait(return_value, waitAnyBatch((int)max_results, return_value), "waitAnyBatch");
}

// PHP: SConcur\Extension\waitAnyTimeoutBatch(int $timeoutMs, int $maxResults): ?list<TaskResultDto>
// Null when no result became ready in time.
PHP_FUNCTION(waitAnyTimeoutBatch)
{
    zend_long timeout_ms;
    zend_long max_results;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ll", &timeout_ms, &max_results) == FAILURE) {
        RETURN_THROWS();
    }

    sconcur_finish_wait(
        return_value,
        waitAnyTimeoutBatch((int)timeout_ms, (int)max_results, return_value),
        "waitAnyTimeoutBatch"
    );
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

    return SUCCESS;
}

/*
 * The result builder's class cache is per request: see the builder above for
 * why a class entry must not outlive the request that declared it.
 */
PHP_RSHUTDOWN_FUNCTION(sconcur)
{
    sconcur_result_cache_reset();
    sconcur_value_class_cache_reset();

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

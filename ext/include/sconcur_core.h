/*
 * The C view of the Rust core: what ext/sconcur.c may call, and the shape of
 * what comes back.
 *
 * Every function below is defined in src/lib.rs and must keep this signature.
 * The two files are one contract with nothing but the linker checking it, so a
 * change here is a change there in the same commit.
 */

#ifndef SCONCUR_CORE_H
#define SCONCUR_CORE_H

#include <stdlib.h>
#include <php.h>

#ifdef __cplusplus
extern "C" {
#endif

/*
 * The outcome of a wait export. READY: the core wrote the result (or the list of
 * results) into the zval the glue handed it. TIMEOUT: nothing became ready, the
 * zval is untouched and the glue answers null. FAILED: the core already threw
 * the PHP exception that reports why, through sconcur_throw.
 */
enum {
	SCONCUR_WAIT_READY = 0,
	SCONCUR_WAIT_TIMEOUT = 1,
	SCONCUR_WAIT_FAILED = 2,
};

/*
 * The fields of one task result, as the core hands them to the glue's result
 * builder on the PHP thread. Mirrors ResultFields in src/lib.rs field for
 * field. The byte pointers are views into the core's own memory and are read
 * only for the duration of the builder call; method_case is the NUL-terminated
 * name of the SConcur\Features\MethodEnum case. The payload is not among the
 * fields: the builder answers with the payload slot, and the core fills it
 * through the value builder below.
 */
typedef struct {
	const char *flow_key;
	size_t flow_key_len;
	const char *method_case;
	const char *task_key;
	size_t task_key_len;
	int is_error;
	int has_next;
	unsigned int execution_ms;
	long long total_execution_ms;
	long long owner_id;
} sconcur_result_t;

/*
 * Defined in sconcur.c, called by the core on the PHP thread, inside a wait
 * export: the builders that turn result fields into the PHP package's
 * TaskResultDto. Both answer the zval of the new object's payload property, to
 * be filled through the value builder, or NULL with a PHP exception pending.
 */
extern zval *sconcur_result_into(zval *destination, const sconcur_result_t *result);
extern zval *sconcur_result_append(zval *list, const sconcur_result_t *result);

/*
 * The value builder: one call per node of the value tree a result carries
 * (src/dto/value.rs), each writing into the zval it is given. Like every Zend
 * allocation, a builder call may bail out (memory_limit, a fatal error): the
 * longjmp skips the core's frames, so the core holds nothing across a builder
 * call whose loss would corrupt state — the lost results are gone with the
 * request, nothing else. The container
 * calls answer the zval of the new element, to be written in turn, or NULL with
 * a PHP exception pending; the slot is valid until the next element is added to
 * the same container, which is why a child is filled before its sibling is
 * asked for. Every (pointer, length) pair is a view into a Rust buffer: not
 * NUL-terminated, and with a length of 0 the pointer is dangling, so a builder
 * reads at most length bytes and none of an empty one.
 */
extern void sconcur_value_null(zval *destination);
extern void sconcur_value_bool(zval *destination, int flag);
extern void sconcur_value_long(zval *destination, long long number);
extern void sconcur_value_double(zval *destination, double number);
extern void sconcur_value_stringl(zval *destination, const char *bytes, size_t length);
extern void sconcur_value_array(zval *destination, unsigned int size);
extern zval *sconcur_array_next_slot(zval *array);
extern zval *sconcur_array_index_slot(zval *array, long long index);
extern zval *sconcur_array_key_slot(zval *array, const char *key, size_t length);
extern int sconcur_value_object(zval *destination, const char *className, size_t length);
extern zval *sconcur_object_property_slot(zval *object, const char *name, size_t length);

/*
 * Throws the exception class the PHP package owns, looked up by its name; a
 * class that cannot be loaded degrades to a plain Error carrying the same
 * message rather than to silence.
 */
extern void sconcur_throw(const char *className, const char *message);

/* Defined in sconcur.c, called from the core's preemption ticker thread. */
extern void sconcur_request_vm_interrupt(void);

/*
 * Defined in sconcur.c, called by the process export on the PHP thread: the two
 * steps of a worker's life only PHP can take. The first runs in a freshly forked
 * child before it is handed back to PHP, the second right before a worker leaves
 * through _exit.
 */
extern void sconcur_after_fork_in_child(void);
extern void sconcur_flush_output(void);

extern char *ping(char *name);

/*
 * The value readers: how the core reads the PHP array a push was given, on the
 * PHP thread, inside the push export. The zval pointers are valid for that call
 * only, so the core copies what it keeps. A reference is read through.
 */
enum {
	SCONCUR_KIND_NULL = 0,
	SCONCUR_KIND_BOOL = 1,
	SCONCUR_KIND_LONG = 2,
	SCONCUR_KIND_DOUBLE = 3,
	SCONCUR_KIND_STRING = 4,
	SCONCUR_KIND_ARRAY = 5,
	SCONCUR_KIND_OBJECT = 6,
	SCONCUR_KIND_OTHER = 7,
};

extern int sconcur_zval_kind(const zval *value);
extern int sconcur_zval_bool(const zval *value);
extern long long sconcur_zval_long(const zval *value);
extern double sconcur_zval_double(const zval *value);
extern const char *sconcur_zval_string(const zval *value, size_t *length);
extern unsigned int sconcur_array_count(const zval *array);
extern int sconcur_array_is_list(const zval *array);
extern const char *sconcur_object_class(const zval *object, size_t *length);
extern int sconcur_object_is_plain(const zval *object);
extern int sconcur_object_refused(const zval *object);

/*
 * Visits every entry of an array, or every public property of an object, in
 * order. A string key comes with its bytes, an integer key with its index and a
 * NULL key pointer. The visit answers 0 to stop the walk, and the iterate call
 * answers what the last visit answered.
 */
typedef int (*sconcur_visit_t)(
	void *context,
	const char *key,
	size_t keyLength,
	long long index,
	const zval *value
);

extern int sconcur_array_iterate(const zval *array, void *context, sconcur_visit_t visit);
extern int sconcur_object_iterate(const zval *object, void *context, sconcur_visit_t visit);

/*
 * Whether a PHP exception is pending. An object's property table may be built by
 * user code (a lazy object's initializer), so the core asks after a walk and
 * refuses the payload rather than enqueue a task with a throw in flight.
 */
extern int sconcur_exception_pending(void);

/* The push export reads payload through the readers above before it returns. */
extern char *push(
	char *flowKey,
	int flowKeyLen,
	char *method,
	int methodLen,
	char *taskKey,
	int taskKeyLen,
	zval *payload,
	long long ownerId
);

extern char *next(char *flowKey, char *taskKey, long long ownerId);

/*
 * The wait exports write into returnValue and answer one of the SCONCUR_WAIT_*
 * codes above: a TaskResultDto for the single-result ones, a list of them for
 * the batch ones.
 */
extern int wait(char *flowKey, int flowKeyLen, zval *returnValue);
extern int waitAny(zval *returnValue);
extern int waitAnyTimeout(int timeoutMs, zval *returnValue);
extern int waitAnyBatch(int maxResults, zval *returnValue);
extern int waitAnyTimeoutBatch(int timeoutMs, int maxResults, zval *returnValue);

extern int tasksCount(void);

extern void stopFlow(char *flowKey, int flowKeyLen);

extern void httpStopAccepting(char *flowKey);
extern void socketStopAccepting(char *flowKey);
extern void wsStopAccepting(char *flowKey);
extern void amqpStopConsuming(char *flowKey);
extern void amqpReopenConsumer(char *flowKey, char *channelId);

extern void preemptionArm(int quantumMs);
extern void preemptionDisarm(void);

extern void destroy(void);
extern char *version(void);

/*
 * Process control (src/process/): one export for everything a worker master asks
 * of the operating system, the command named by command and its parameters read
 * from payload through the value readers. Synchronous and runtime-free, because
 * the process that forks must not run threads. Writes the answer into returnValue
 * and answers SCONCUR_WAIT_READY, or SCONCUR_WAIT_FAILED with the exception
 * already thrown. A fork returns through it twice: in the parent and in the child.
 */
extern int process(char *command, int commandLen, zval *payload, zval *returnValue);

#ifdef __cplusplus
}
#endif

#endif /* SCONCUR_CORE_H */

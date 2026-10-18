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
 * name of the SConcur\Features\MethodEnum case.
 */
typedef struct {
	const char *flow_key;
	size_t flow_key_len;
	const char *method_case;
	const char *task_key;
	size_t task_key_len;
	const char *payload;
	size_t payload_len;
	int is_error;
	int has_next;
	unsigned int execution_ms;
	long long total_execution_ms;
	long long owner_id;
} sconcur_result_t;

/*
 * Defined in sconcur.c, called by the core on the PHP thread, inside a wait
 * export: the builders that turn result fields into the PHP package's
 * TaskResultDto. The int ones answer 0 when they could not, with a PHP exception
 * already pending.
 */
extern void sconcur_list_init(zval *list, unsigned int size);
extern int sconcur_result_into(zval *destination, const sconcur_result_t *result);
extern int sconcur_result_append(zval *list, const sconcur_result_t *result);

/*
 * Throws the exception class the PHP package owns, looked up by its name; a
 * class that cannot be loaded degrades to a plain Error carrying the same
 * message rather than to silence.
 */
extern void sconcur_throw(const char *className, const char *message);

/* Defined in sconcur.c, called from the core's preemption ticker thread. */
extern void sconcur_request_vm_interrupt(void);

extern char *ping(char *name);

extern char *push(
	char *flowKey,
	int flowKeyLen,
	char *method,
	int methodLen,
	char *taskKey,
	int taskKeyLen,
	void *payload,
	int payloadLen,
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

#ifdef __cplusplus
}
#endif

#endif /* SCONCUR_CORE_H */

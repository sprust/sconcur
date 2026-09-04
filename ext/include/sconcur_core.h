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

#ifdef __cplusplus
extern "C" {
#endif

/*
 * One buffer the core hands back: `data` and `err` are malloc'ed there and freed
 * by the caller, and the glue frees every one of them.
 */
typedef struct {
	void *data;
	int len;
	char *err;
} buffer_result_t;

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

extern buffer_result_t wait(char *flowKey, int flowKeyLen);
extern buffer_result_t waitAny(void);
extern buffer_result_t waitAnyTimeout(int timeoutMs);
extern buffer_result_t waitAnyBatch(int maxResults);
extern buffer_result_t waitAnyTimeoutBatch(int timeoutMs, int maxResults);

extern int tasksCount(void);

extern void stopFlow(char *flowKey, int flowKeyLen);

extern void httpStopAccepting(char *flowKey);
extern void socketStopAccepting(char *flowKey);
extern void wsStopAccepting(char *flowKey);
extern void amqpStopConsuming(char *flowKey);

extern void preemptionArm(int quantumMs);
extern void preemptionDisarm(void);

extern void destroy(void);
extern char *version(void);

#ifdef __cplusplus
}
#endif

#endif /* SCONCUR_CORE_H */

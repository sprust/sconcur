/*
 * The C view of the Rust core — the hand-written counterpart of the
 * _cgo_export.h cgo generates for the Go build.
 *
 * ext-rust/sconcur.c is a copy of ext/sconcur.c with exactly one line changed
 * (this include in place of "_cgo_export.h"), so the PHP glue under test is the
 * production glue: the same arginfo, the same zend_parse_parameters, the same
 * free() of every buffer the core hands back.
 *
 * Every function below is defined in src/lib.rs and must keep this signature.
 */

#ifndef SCONCUR_CORE_H
#define SCONCUR_CORE_H

#include <stdlib.h>

#ifdef __cplusplus
extern "C" {
#endif

/*
 * Mirrors the struct main.go declares in its cgo preamble. `data` and `err` are
 * malloc'ed by the core and freed by the caller.
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

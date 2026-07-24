// Command parkwake-spike isolates the one contested number behind plan phase 2
// (shared memory + eventfd): the cost to wake a *consumer* that is parked
// (a) inside a blocking cgo call on a Go channel — today's WaitAny — versus
// (b) on a plain eventfd read in C, outside the Go runtime.
//
// In both variants the consumer is a foreign (C-created) OS thread, exactly like
// the PHP thread, and the producer is a Go goroutine, exactly like a task. Each
// round-trip parks the consumer and has the producer wake it (the trickle
// regime, where the results buffer is empty). We report wall + CPU per round-trip.
//
// Reading:
//   - "go chan pingpong"  : goroutine<->goroutine, no cgo, no foreign thread — the floor.
//   - "cgo-blocked chan"  : C thread enters Go and blocks on a channel; producer
//                           goroutine wakes it. This is today's WaitAny wake.
//   - "eventfd (C thread)": C thread blocks on read(eventfd); producer goroutine
//                           writes the eventfd. This is phase 2's wake.
//
// If cgo-blocked >> eventfd, phase 2's wake mechanism is genuinely cheaper and
// the rewrite is worth measuring further. If they are close, phase 2 buys little.
package main

/*
#include <pthread.h>
#include <sys/eventfd.h>
#include <unistd.h>
#include <stdint.h>
#include <stdlib.h>

extern void goPingChan();

// Channel variant: the C consumer thread enters Go (goPingChan) n times; each
// call triggers the producer goroutine and blocks until it answers.
static void* consumer_chan(void* arg) {
    long n = (long)(intptr_t)arg;
    for (long i = 0; i < n; i++) {
        goPingChan();
    }
    return NULL;
}

static void run_consumer_chan(long n) {
    pthread_t t;
    pthread_create(&t, NULL, consumer_chan, (void*)(intptr_t)n);
    pthread_join(t, NULL);
}

// Eventfd variant: the C consumer thread signals the producer (efd_req) and
// blocks on efd_res — no cgo per round-trip. The producer is a Go goroutine
// blocked on efd_req.
typedef struct { long n; int efd_req; int efd_res; } ev_args;

static void* consumer_eventfd(void* arg) {
    ev_args* a = (ev_args*)arg;
    uint64_t one = 1, buf;
    for (long i = 0; i < a->n; i++) {
        ssize_t w = write(a->efd_req, &one, 8);
        (void)w;
        ssize_t r = read(a->efd_res, &buf, 8);
        (void)r;
    }
    return NULL;
}

static void run_consumer_eventfd(long n, int efd_req, int efd_res) {
    ev_args a; a.n = n; a.efd_req = efd_req; a.efd_res = efd_res;
    pthread_t t;
    pthread_create(&t, NULL, consumer_eventfd, &a);
    pthread_join(t, NULL);
}

static int make_eventfd() { return eventfd(0, 0); }
*/
import "C"

import (
	"fmt"
	"syscall"
	"time"
)

var requestCh = make(chan struct{})
var resultCh = make(chan struct{})

//export goPingChan
func goPingChan() {
	requestCh <- struct{}{} // wake the producer goroutine
	<-resultCh              // park inside cgo until the producer answers
}

func rusageCPUNanos() int64 {
	var usage syscall.Rusage

	if err := syscall.Getrusage(syscall.RUSAGE_SELF, &usage); err != nil {
		panic(err)
	}

	user := int64(usage.Utime.Sec)*1_000_000_000 + int64(usage.Utime.Usec)*1_000
	sys := int64(usage.Stime.Sec)*1_000_000_000 + int64(usage.Stime.Usec)*1_000

	return user + sys
}

type measurement struct {
	wallNsPerOp float64
	cpuNsPerOp  float64
}

func measure(n int, run func()) measurement {
	cpuBefore := rusageCPUNanos()
	wallBefore := time.Now()

	run()

	wallNs := float64(time.Since(wallBefore).Nanoseconds())
	cpuNs := float64(rusageCPUNanos() - cpuBefore)

	return measurement{
		wallNsPerOp: wallNs / float64(n),
		cpuNsPerOp:  cpuNs / float64(n),
	}
}

func main() {
	const n = 300000

	// Channel producer goroutine: answers each request, waking the parked consumer.
	go func() {
		for {
			<-requestCh
			resultCh <- struct{}{}
		}
	}()

	// Floor: pure goroutine<->goroutine channel ping-pong (no cgo, no foreign thread).
	goFloor := func() {
		reqCh := make(chan struct{})
		resCh := make(chan struct{})

		go func() {
			for range reqCh {
				resCh <- struct{}{}
			}
		}()

		for i := 0; i < n; i++ {
			reqCh <- struct{}{}
			<-resCh
		}
	}

	// Eventfd setup + producer goroutine.
	efdReq := int(C.make_eventfd())
	efdRes := int(C.make_eventfd())

	if efdReq < 0 || efdRes < 0 {
		panic("eventfd creation failed")
	}

	go func() {
		buf := make([]byte, 8)
		one := []byte{1, 0, 0, 0, 0, 0, 0, 0}

		for {
			if _, err := syscall.Read(efdReq, buf); err != nil {
				continue
			}

			if _, err := syscall.Write(efdRes, one); err != nil {
				continue
			}
		}
	}()

	// Warm each path a little before measuring.
	warm := 20000
	for i := 0; i < warm; i++ {
		goPingChan()
	}

	C.run_consumer_chan(C.long(warm))
	C.run_consumer_eventfd(C.long(warm), C.int(efdReq), C.int(efdRes))

	floor := measure(n, goFloor)
	chanCgo := measure(n, func() { C.run_consumer_chan(C.long(n)) })
	eventfd := measure(n, func() { C.run_consumer_eventfd(C.long(n), C.int(efdReq), C.int(efdRes)) })

	fmt.Printf("round-trips per variant: %d\n\n", n)
	fmt.Printf("%-26s %14s %14s\n", "variant", "wall ns/op", "cpu ns/op")
	fmt.Printf("%-26s %14.1f %14.1f\n", "go chan pingpong (floor)", floor.wallNsPerOp, floor.cpuNsPerOp)
	fmt.Printf("%-26s %14.1f %14.1f\n", "cgo-blocked chan (WaitAny)", chanCgo.wallNsPerOp, chanCgo.cpuNsPerOp)
	fmt.Printf("%-26s %14.1f %14.1f\n", "eventfd (C thread, phase2)", eventfd.wallNsPerOp, eventfd.cpuNsPerOp)
}

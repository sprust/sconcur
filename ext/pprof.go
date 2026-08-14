package main

import (
	"net/http"
	_ "net/http/pprof"
	"os"
	"runtime"

	"sconcur/internal/logger"
)

// Optional pprof endpoint for profiling sessions (the attribution plan, phase 3).
// Off unless SCONCUR_PPROF_ADDR is set (e.g. "127.0.0.1:6060"), so production
// workers never open a debug port. With a reuse-port worker pool, set the env
// for a single worker only — each process runs its own Go runtime, and two
// workers cannot bind the same pprof address.
//
// SCONCUR_PPROF_BLOCK=1 / SCONCUR_PPROF_MUTEX=1 additionally enable the block
// and mutex profiles (measurable overhead; profiling sessions only).
func init() {
	address := os.Getenv("SCONCUR_PPROF_ADDR")

	if address == "" {
		return
	}

	if os.Getenv("SCONCUR_PPROF_BLOCK") == "1" {
		runtime.SetBlockProfileRate(1)
	}

	if os.Getenv("SCONCUR_PPROF_MUTEX") == "1" {
		runtime.SetMutexProfileFraction(1)
	}

	go func() {
		err := http.ListenAndServe(address, nil)

		if err != nil {
			logger.Write("pprof: listen " + address + ": " + err.Error())
		}
	}()
}

package stats

import (
	"os"
	"runtime/metrics"
	"strconv"
	"strings"
	"time"
)

// clockTicksPerSecond is USER_HZ — the unit of the utime/stime fields in
// /proc/self/stat. It is fixed at 100 on virtually every Linux build (the kernel
// default), so we use it directly rather than pulling in cgo for sysconf.
const clockTicksPerSecond = 100.0

// goRuntimeMetric is the runtime/metrics name for all memory the Go runtime has
// mapped read-write — the equivalent of runtime.MemStats.Sys, but read without a
// stop-the-world (ReadMemStats stops the world; metrics.Read does not).
const goRuntimeMetric = "/memory/classes/total:bytes"

// readMemory builds the process memory split: the whole resident set from the OS
// (/proc/self/status VmRSS), the Go runtime's own footprint (runtime/metrics), and
// the remainder attributed to the non-extension side (PHP interpreter).
func readMemory() Memory {
	rssBytes := readRssBytes()

	goRuntimeBytes := readGoRuntimeBytes()

	nonExtensionBytes := rssBytes - goRuntimeBytes

	if nonExtensionBytes < 0 {
		nonExtensionBytes = 0
	}

	return Memory{
		RssBytes:          rssBytes,
		GoRuntimeBytes:    goRuntimeBytes,
		NonExtensionBytes: nonExtensionBytes,
	}
}

// readGoRuntimeBytes reads the Go runtime's total mapped memory via runtime/metrics,
// which (unlike runtime.ReadMemStats) does not stop the world — so sampling it on the
// hot push cadence does not pause the worker's request handlers.
func readGoRuntimeBytes() int64 {
	samples := []metrics.Sample{{Name: goRuntimeMetric}}

	metrics.Read(samples)

	if samples[0].Value.Kind() != metrics.KindUint64 {
		return 0
	}

	return int64(samples[0].Value.Uint64())
}

// readRssBytes reads the resident set size from /proc/self/status (the VmRSS
// line, reported in kB). Returns 0 if the value cannot be read.
func readRssBytes() int64 {
	contents, err := os.ReadFile("/proc/self/status")

	if err != nil {
		return 0
	}

	for _, line := range strings.Split(string(contents), "\n") {
		if !strings.HasPrefix(line, "VmRSS:") {
			continue
		}

		fields := strings.Fields(line)

		if len(fields) < 2 {
			return 0
		}

		kiloBytes, err := strconv.ParseInt(fields[1], 10, 64)

		if err != nil {
			return 0
		}

		return kiloBytes * 1024
	}

	return 0
}

// cpuSampler turns the monotonically growing CPU time of the process into a
// rolling percentage: each call diffs the consumed CPU ticks against wall time
// since the previous call. The first call only seeds the baseline and returns 0.
type cpuSampler struct {
	previousTicks float64
	previousWall  time.Time
	seeded        bool
}

// sample returns the CPU usage percent over the interval since the previous call.
// now is passed in so the writer loop uses one consistent clock for CPU and the
// snapshot timestamp.
func (sampler *cpuSampler) sample(now time.Time) float64 {
	ticks := readProcessCpuTicks()

	if !sampler.seeded {
		sampler.seeded = true
		sampler.previousTicks = ticks
		sampler.previousWall = now

		return 0
	}

	deltaTicks := ticks - sampler.previousTicks
	deltaWallSeconds := now.Sub(sampler.previousWall).Seconds()

	sampler.previousTicks = ticks
	sampler.previousWall = now

	if deltaWallSeconds <= 0 {
		return 0
	}

	cpuSeconds := deltaTicks / clockTicksPerSecond

	return cpuSeconds / deltaWallSeconds * 100.0
}

// readProcessCpuTicks reads utime+stime (in clock ticks) from /proc/self/stat.
// The comm field (2nd) may contain spaces and parentheses, so we parse the fields
// after the last ')': there state is field 3, making utime field 14 (index 11)
// and stime field 15 (index 12). Returns 0 on any parse failure.
func readProcessCpuTicks() float64 {
	contents, err := os.ReadFile("/proc/self/stat")

	if err != nil {
		return 0
	}

	text := string(contents)

	lastParen := strings.LastIndex(text, ")")

	if lastParen < 0 || lastParen+2 > len(text) {
		return 0
	}

	fields := strings.Fields(text[lastParen+2:])

	// index 11 = utime (field 14), index 12 = stime (field 15).
	if len(fields) < 13 {
		return 0
	}

	utime, errUtime := strconv.ParseFloat(fields[11], 64)
	stime, errStime := strconv.ParseFloat(fields[12], 64)

	if errUtime != nil || errStime != nil {
		return 0
	}

	return utime + stime
}

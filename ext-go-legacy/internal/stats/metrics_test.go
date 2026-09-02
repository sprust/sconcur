package stats

import (
	"testing"
	"time"
)

// burnCpu does a little busy work so utime advances between two CPU samples.
func burnCpu() {
	sum := 0

	for i := 0; i < 5_000_000; i++ {
		sum += i
	}

	_ = sum
}

// TestCpuSamplerSeedsToZero: the first sample only establishes the baseline.
func TestCpuSamplerSeedsToZero(t *testing.T) {
	var sampler cpuSampler

	if got := sampler.sample(time.Now()); got != 0 {
		t.Errorf("first sample = %v, want 0 (seed only)", got)
	}

	if !sampler.seeded {
		t.Error("sampler not marked seeded after the first sample")
	}
}

// TestCpuSamplerZeroWhenWallDoesNotAdvance guards the divide-by-zero edge: two
// samples at the same instant must yield 0, not INF/NaN.
func TestCpuSamplerZeroWhenWallDoesNotAdvance(t *testing.T) {
	var sampler cpuSampler

	now := time.Now()

	sampler.sample(now)

	if got := sampler.sample(now); got != 0 {
		t.Errorf("sample at same instant = %v, want 0 (no divide by zero)", got)
	}
}

// TestCpuSamplerNonNegativeOverInterval: a real interval after burning CPU yields a
// finite, non-negative percentage.
func TestCpuSamplerNonNegativeOverInterval(t *testing.T) {
	var sampler cpuSampler

	base := time.Now()

	sampler.sample(base)

	burnCpu()

	got := sampler.sample(base.Add(time.Second))

	if got < 0 {
		t.Errorf("cpu percent = %v, want >= 0", got)
	}
}

// TestReadMemorySplit checks the memory split is internally consistent: the
// non-extension remainder is RSS minus the Go runtime footprint, clamped at 0.
func TestReadMemorySplit(t *testing.T) {
	memory := readMemory()

	if memory.RssBytes == 0 {
		t.Skip("no /proc RSS available (non-Linux)")
	}

	if memory.GoRuntimeBytes <= 0 {
		t.Errorf("GoRuntimeBytes = %d, want > 0", memory.GoRuntimeBytes)
	}

	want := memory.RssBytes - memory.GoRuntimeBytes

	if want < 0 {
		want = 0
	}

	if memory.NonExtensionBytes != want {
		t.Errorf("NonExtensionBytes = %d, want %d (rss=%d go=%d)", memory.NonExtensionBytes, want, memory.RssBytes, memory.GoRuntimeBytes)
	}
}

// TestReadProcessCpuTicksMonotonic: ticks never go backwards (utime+stime only grow).
func TestReadProcessCpuTicksMonotonic(t *testing.T) {
	first := readProcessCpuTicks()

	if first < 0 {
		t.Fatalf("ticks = %v, want >= 0", first)
	}

	burnCpu()

	second := readProcessCpuTicks()

	if second < first {
		t.Errorf("ticks went backwards: first=%v second=%v", first, second)
	}
}

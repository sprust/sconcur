// Command ws-load is a WebSocket load generator for the load-test benchmark (the WS
// counterpart of wrk, which is HTTP-only). It opens N persistent connections, each
// doing back-to-back request/reply round-trips for a fixed duration, and reports the
// sustained throughput and latency percentiles. Run it inside the `php` container,
// pinned to the load cores, against the local ws-server pool (see ws-load-stats.sh).
//
//	go run ./cmd/ws-load -url ws://127.0.0.1:18090/ -conns 256 -duration 20 -msg all
package main

import (
	"context"
	"flag"
	"fmt"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
)

const (
	// histogram resolution: 0.1 ms per bucket, up to ~10 s.
	bucketMicros = 100
	bucketCount  = 100_000
	opTimeout    = 30 * time.Second
)

func main() {
	url := flag.String("url", "ws://127.0.0.1:18090/", "ws:// URL of the server pool")
	conns := flag.Int("conns", 256, "concurrent persistent connections")
	durationSeconds := flag.Int("duration", 20, "load duration in seconds")
	message := flag.String("msg", "ping", "message sent on each round-trip")
	flag.Parse()

	deadline := time.Now().Add(time.Duration(*durationSeconds) * time.Second)

	var (
		roundTrips atomic.Int64
		errors     atomic.Int64
		histogram  [bucketCount]atomic.Int64
		waitGroup  sync.WaitGroup
	)

	record := func(elapsed time.Duration) {
		bucket := int(elapsed.Microseconds() / bucketMicros)

		if bucket < 0 {
			bucket = 0
		}

		if bucket >= bucketCount {
			bucket = bucketCount - 1
		}

		histogram[bucket].Add(1)
	}

	start := time.Now()

	for index := 0; index < *conns; index++ {
		waitGroup.Add(1)

		go func() {
			defer waitGroup.Done()

			runConnection(*url, []byte(*message), deadline, &roundTrips, &errors, record)
		}()
	}

	waitGroup.Wait()

	elapsed := time.Since(start)
	total := roundTrips.Load()
	rps := float64(total) / elapsed.Seconds()

	histogramSnapshot := make([]int64, bucketCount)

	for index := range histogram {
		histogramSnapshot[index] = histogram[index].Load()
	}

	fmt.Printf("connections   : %d\n", *conns)
	fmt.Printf("duration      : %.1fs\n", elapsed.Seconds())
	fmt.Printf("message       : %q\n", *message)
	fmt.Printf("round-trips   : %d  (%d errors)\n", total, errors.Load())
	fmt.Printf("throughput    : %.0f rt/s\n", rps)
	fmt.Printf(
		"latency       : p50 %.1f ms · p90 %.1f ms · p99 %.1f ms · max %.1f ms\n",
		percentileMs(histogramSnapshot, total, 0.50),
		percentileMs(histogramSnapshot, total, 0.90),
		percentileMs(histogramSnapshot, total, 0.99),
		maxMs(histogramSnapshot),
	)
}

// runConnection dials and drives one persistent connection: back-to-back round-trips
// until the deadline, reconnecting on any error. Each round-trip's latency is recorded.
func runConnection(
	url string,
	message []byte,
	deadline time.Time,
	roundTrips *atomic.Int64,
	errors *atomic.Int64,
	record func(time.Duration),
) {
	for time.Now().Before(deadline) {
		dialCtx, dialCancel := context.WithTimeout(context.Background(), opTimeout)
		conn, _, err := websocket.Dial(dialCtx, url, nil)
		dialCancel()

		if err != nil {
			errors.Add(1)
			time.Sleep(10 * time.Millisecond)

			continue
		}

		conn.SetReadLimit(-1)

		for time.Now().Before(deadline) {
			roundTripStart := time.Now()

			if !roundTrip(conn, message) {
				errors.Add(1)

				break
			}

			record(time.Since(roundTripStart))
			roundTrips.Add(1)
		}

		_ = conn.CloseNow()
	}
}

// roundTrip sends one message and reads one reply, bounded by opTimeout. Returns false
// on any write/read error.
func roundTrip(conn *websocket.Conn, message []byte) bool {
	ctx, cancel := context.WithTimeout(context.Background(), opTimeout)
	defer cancel()

	if err := conn.Write(ctx, websocket.MessageText, message); err != nil {
		return false
	}

	if _, _, err := conn.Read(ctx); err != nil {
		return false
	}

	return true
}

// percentileMs returns the latency in milliseconds at the given quantile from the
// histogram.
func percentileMs(histogram []int64, total int64, quantile float64) float64 {
	if total == 0 {
		return 0
	}

	threshold := int64(quantile * float64(total))

	var cumulative int64

	for bucket, count := range histogram {
		cumulative += count

		if cumulative >= threshold {
			return float64(bucket) * bucketMicros / 1000.0
		}
	}

	return maxMs(histogram)
}

// maxMs returns the highest observed latency bucket in milliseconds.
func maxMs(histogram []int64) float64 {
	for bucket := len(histogram) - 1; bucket >= 0; bucket-- {
		if histogram[bucket] > 0 {
			return float64(bucket) * bucketMicros / 1000.0
		}
	}

	return 0
}

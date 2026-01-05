package helpers

import "time"

func CalcExecutionMs(start time.Time) int {
	return int(time.Since(start).Milliseconds())
}

package httpclient_feature

import (
	"os"
	"testing"
)

func TestDownloadModeToFlags(t *testing.T) {
	cases := map[int]int{
		downloadModeReplace: os.O_WRONLY | os.O_CREATE | os.O_TRUNC,
		downloadModeCreate:  os.O_WRONLY | os.O_CREATE | os.O_EXCL,
		downloadModeAppend:  os.O_WRONLY | os.O_CREATE | os.O_APPEND,
	}

	for mode, expected := range cases {
		flags, ok := downloadModeToFlags(mode)

		if !ok {
			t.Fatalf("mode %d reported as invalid", mode)
		}

		if flags != expected {
			t.Fatalf("mode %d flags = %d, want %d", mode, flags, expected)
		}
	}

	for _, mode := range []int{0, 4, 99, -1} {
		if _, ok := downloadModeToFlags(mode); ok {
			t.Fatalf("mode %d should be invalid", mode)
		}
	}
}

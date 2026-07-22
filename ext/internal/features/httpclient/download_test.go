package httpclient_feature

import (
	"os"
	"testing"
)

func TestDownloadModeToFlags(t *testing.T) {
	cases := map[string]int{
		downloadModeReplace: os.O_WRONLY | os.O_CREATE | os.O_TRUNC,
		downloadModeCreate:  os.O_WRONLY | os.O_CREATE | os.O_EXCL,
		downloadModeAppend:  os.O_WRONLY | os.O_CREATE | os.O_APPEND,
	}

	for mode, expected := range cases {
		flags, ok := downloadModeToFlags(mode)

		if !ok {
			t.Fatalf("mode %q reported as invalid", mode)
		}

		if flags != expected {
			t.Fatalf("mode %q flags = %d, want %d", mode, flags, expected)
		}
	}

	for _, mode := range []string{"", "nope", "1", "w"} {
		if _, ok := downloadModeToFlags(mode); ok {
			t.Fatalf("mode %q should be invalid", mode)
		}
	}
}

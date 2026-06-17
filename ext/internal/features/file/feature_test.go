package file_feature

import (
	"os"
	"path/filepath"
	"testing"
)

func TestModeToFlags(t *testing.T) {
	cases := map[string]int{
		"r":  os.O_RDONLY,
		"r+": os.O_RDWR,
		"w":  os.O_WRONLY | os.O_CREATE | os.O_TRUNC,
		"w+": os.O_RDWR | os.O_CREATE | os.O_TRUNC,
		"a":  os.O_WRONLY | os.O_CREATE | os.O_APPEND,
		"a+": os.O_RDWR | os.O_CREATE | os.O_APPEND,
		"x":  os.O_WRONLY | os.O_CREATE | os.O_EXCL,
		"x+": os.O_RDWR | os.O_CREATE | os.O_EXCL,
		"c":  os.O_WRONLY | os.O_CREATE,
		"c+": os.O_RDWR | os.O_CREATE,
	}

	for mode, expected := range cases {
		flags, ok := modeToFlags(mode)

		if !ok {
			t.Fatalf("mode %q reported as invalid", mode)
		}

		if flags != expected {
			t.Fatalf("mode %q flags = %d, want %d", mode, flags, expected)
		}
	}
}

func TestModeToFlagsInvalid(t *testing.T) {
	for _, mode := range []string{"", "z", "rw", "r++", "b"} {
		if _, ok := modeToFlags(mode); ok {
			t.Fatalf("mode %q should be invalid", mode)
		}
	}
}

func TestReadUpToReadsFullThenEof(t *testing.T) {
	path := filepath.Join(t.TempDir(), "data")

	if err := os.WriteFile(path, []byte("abcdef"), 0o644); err != nil {
		t.Fatalf("write file: %v", err)
	}

	file, err := os.Open(path)

	if err != nil {
		t.Fatalf("open file: %v", err)
	}

	defer file.Close()

	data, eof, err := readUpTo(file, 4)

	if err != nil {
		t.Fatalf("first read: %v", err)
	}

	if string(data) != "abcd" {
		t.Fatalf("first read = %q, want %q", string(data), "abcd")
	}

	if eof {
		t.Fatalf("first read should not be eof")
	}

	data, eof, err = readUpTo(file, 4)

	if err != nil {
		t.Fatalf("second read: %v", err)
	}

	if string(data) != "ef" {
		t.Fatalf("second read = %q, want %q", string(data), "ef")
	}

	if !eof {
		t.Fatalf("second read should be eof")
	}

	data, eof, err = readUpTo(file, 4)

	if err != nil {
		t.Fatalf("third read: %v", err)
	}

	if len(data) != 0 || !eof {
		t.Fatalf("third read = %q eof=%v, want empty eof", string(data), eof)
	}
}

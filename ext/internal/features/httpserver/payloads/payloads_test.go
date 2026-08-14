package payloads

import (
	"reflect"
	"testing"

	"github.com/vmihailenco/msgpack/v5"
)

// The hand-written encoder must stay wire-compatible with the struct tags the
// reflective decoder reads: a round trip through Marshal (custom encoder) and
// Unmarshal (reflection) must reproduce the event exactly, so a renamed key or
// a forgotten field breaks here instead of on the PHP side.
func TestRequestEventCustomEncoderRoundTrip(t *testing.T) {
	event := &RequestEvent{
		RequestId: "flow:r:42",
		Method:    "POST",
		Path:      "/upload",
		Query:     "a=1&b=2",
		Headers: map[string][]string{
			"Host":       {"example.test:8080"},
			"X-Multi":    {"one", "two"},
			"User-Agent": {"wrk/4.1.0"},
		},
		Body:       "first-chunk",
		BodyKey:    "flow:r:42:body",
		RemoteAddr: "10.0.0.1:54321",
		Host:       "example.test:8080",
		Proto:      "HTTP/1.1",
	}

	serialized, err := msgpack.Marshal(event)

	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	var decoded RequestEvent

	if err := msgpack.Unmarshal(serialized, &decoded); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if !reflect.DeepEqual(*event, decoded) {
		t.Fatalf("round trip mismatch:\nsent: %+v\ngot:  %+v", *event, decoded)
	}
}

func TestRequestEventCustomEncoderEmptyHeaders(t *testing.T) {
	event := &RequestEvent{RequestId: "r"}

	serialized, err := msgpack.Marshal(event)

	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	var decoded RequestEvent

	if err := msgpack.Unmarshal(serialized, &decoded); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if decoded.RequestId != "r" || len(decoded.Headers) != 0 {
		t.Fatalf("round trip mismatch: %+v", decoded)
	}
}

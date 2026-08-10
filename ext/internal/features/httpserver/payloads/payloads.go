// Package payloads holds the Go counterparts of the PHP HTTP-server payload
// objects (SConcur\Features\HttpServer\Payloads\*) and the request event the
// server streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
package payloads

import (
	"github.com/vmihailenco/msgpack/v5"
)

// ServePayload is the payload of an httpStart command — the listener address and
// the server tuning parameters (all timeouts in milliseconds, body limit in
// bytes). Defaults are supplied by the PHP side.
// PHP: SConcur\Features\HttpServer\Payloads\ServePayload.
type ServePayload struct {
	Address             string `json:"ad" msgpack:"ad"`
	ReadHeaderTimeoutMs int    `json:"rht" msgpack:"rht"`
	ReadTimeoutMs       int    `json:"rt" msgpack:"rt"`
	WriteTimeoutMs      int    `json:"wt" msgpack:"wt"`
	IdleTimeoutMs       int    `json:"it" msgpack:"it"`
	ShutdownTimeoutMs   int    `json:"sht" msgpack:"sht"`
	MaxRequestBody      int64  `json:"mrb" msgpack:"mrb"`
	// MaxConcurrency caps the number of requests handled at once (0 = unlimited).
	// Bounds goroutines, buffered request bodies (memory) and PHP coroutines.
	MaxConcurrency int `json:"mc" msgpack:"mc"`
	// HandlerTimeoutMs bounds how long the server waits for a handler to start
	// responding before answering 504 and freeing the slot (0 = disabled).
	HandlerTimeoutMs int `json:"hto" msgpack:"hto"`
	// ReusePort sets SO_REUSEPORT so several processes can bind the same address
	// and the kernel load-balances connections across them (process-per-core).
	ReusePort bool `json:"rp" msgpack:"rp"`
	// TelemetrySocket is the collector's unix socket the worker pushes snapshots to
	// (empty = push off). Under the master it is injected from runtimeDir/name.
	TelemetrySocket string `json:"ts" msgpack:"ts"`
	// ServerName labels the snapshot (the pool scope the collector aggregates by).
	ServerName string `json:"sn" msgpack:"sn"`
	// TelemetryIntervalMs is the snapshot-sample/push cadence (0 = default).
	TelemetryIntervalMs int `json:"ti" msgpack:"ti"`
}

// RespondPayload is the payload of an httpRespond command — one write a PHP
// request-handler coroutine sends back for a given request. Op selects the kind
// of write (0 one-shot full response, 1 stream head, 2 stream chunk, 3 stream
// end). Headers are multi-valued so a handler can emit several Set-Cookie (etc.)
// entries.
// PHP: SConcur\Features\HttpServer\Payloads\RespondPayload.
type RespondPayload struct {
	RequestId string              `json:"rid" msgpack:"rid"`
	Op        int                 `json:"op" msgpack:"op"`
	Status    int                 `json:"st" msgpack:"st"`
	Headers   map[string][]string `json:"hd" msgpack:"hd"`
	Body      string              `json:"bd" msgpack:"bd"`
	// NoResult marks a fire-and-forget write: publish no task result for it (the
	// PHP coroutine does not await one — the final write of a full response).
	NoResult bool `json:"nr" msgpack:"nr"`
}

// RequestEvent is what the server emits to PHP for each accepted request (it is
// MessagePack-marshaled into the streaming result's payload). PHP decodes it
// into SConcur\Features\HttpServer\Dto\Request.
type RequestEvent struct {
	RequestId string              `json:"rid" msgpack:"rid"`
	Method    string              `json:"mt" msgpack:"mt"`
	Path      string              `json:"pt" msgpack:"pt"`
	Query     string              `json:"qr" msgpack:"qr"`
	Headers   map[string][]string `json:"hd" msgpack:"hd"`
	// Body is the inline first chunk of the request body. BodyKey is the streaming
	// state key for the remainder, or "" when the whole body fits in Body.
	Body       string `json:"bd" msgpack:"bd"`
	BodyKey    string `json:"bk" msgpack:"bk"`
	RemoteAddr string `json:"ra" msgpack:"ra"`
	Host       string `json:"ho" msgpack:"ho"`
	Proto      string `json:"pr" msgpack:"pr"`
}

var _ msgpack.CustomEncoder = (*RequestEvent)(nil)

// EncodeMsgpack writes the event by hand instead of through the reflection
// encoder: one event is marshaled per accepted request, and the reflective
// walk (plus its allocations) was a measurable slice of the request cost
// (the attribution plan, phase 5). The wire bytes stay a plain msgpack map
// with the same keys as the struct tags, so the PHP side is unaffected.
// Keep the keys in sync with the tags above.
func (e *RequestEvent) EncodeMsgpack(encoder *msgpack.Encoder) error {
	if err := encoder.EncodeMapLen(10); err != nil {
		return err
	}

	fields := [...]struct {
		key   string
		value string
	}{
		{"rid", e.RequestId},
		{"mt", e.Method},
		{"pt", e.Path},
		{"qr", e.Query},
		{"bd", e.Body},
		{"bk", e.BodyKey},
		{"ra", e.RemoteAddr},
		{"ho", e.Host},
		{"pr", e.Proto},
	}

	for _, field := range fields {
		if err := encoder.EncodeString(field.key); err != nil {
			return err
		}

		if err := encoder.EncodeString(field.value); err != nil {
			return err
		}
	}

	if err := encoder.EncodeString("hd"); err != nil {
		return err
	}

	if err := encoder.EncodeMapLen(len(e.Headers)); err != nil {
		return err
	}

	for name, values := range e.Headers {
		if err := encoder.EncodeString(name); err != nil {
			return err
		}

		if err := encoder.EncodeArrayLen(len(values)); err != nil {
			return err
		}

		for _, value := range values {
			if err := encoder.EncodeString(value); err != nil {
				return err
			}
		}
	}

	return nil
}

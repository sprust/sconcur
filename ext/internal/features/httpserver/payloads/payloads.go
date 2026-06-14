// Package payloads holds the Go counterparts of the PHP HTTP-server payload
// objects (SConcur\Features\HttpServer\Payloads\*) and the request event the
// server streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
package payloads

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
}

// RequestEvent is what the server emits to PHP for each accepted request (it is
// MessagePack-marshaled into the streaming result's payload). PHP decodes it
// into SConcur\Features\HttpServer\Dto\Request.
type RequestEvent struct {
	RequestId  string              `json:"rid" msgpack:"rid"`
	Method     string              `json:"mt" msgpack:"mt"`
	Path       string              `json:"pt" msgpack:"pt"`
	Query      string              `json:"qr" msgpack:"qr"`
	Headers    map[string][]string `json:"hd" msgpack:"hd"`
	Body       string              `json:"bd" msgpack:"bd"`
	RemoteAddr string              `json:"ra" msgpack:"ra"`
	Host       string              `json:"ho" msgpack:"ho"`
	Proto      string              `json:"pr" msgpack:"pr"`
}

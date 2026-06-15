// Package payloads holds the Go counterparts of the PHP HTTP-client payload
// objects (SConcur\Features\HttpClient\Payloads\*) and the response metadata the
// client streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
package payloads

// RequestPayload is the payload of an httpRequest command — one request to send,
// plus the per-request tuning (timeouts in milliseconds, body limit in bytes).
// Defaults are supplied by the PHP side (HttpClientOptions). RequestTimeoutMs
// carries the mandatory hard execution limit for the whole operation.
// PHP: SConcur\Features\HttpClient\Payloads\RequestPayload.
type RequestPayload struct {
	Method  string              `json:"m" msgpack:"m"`
	Url     string              `json:"u" msgpack:"u"`
	Headers map[string][]string `json:"h" msgpack:"h"`
	Body    string              `json:"b" msgpack:"b"`
	// RequestTimeoutMs is the hard limit for the whole operation (connect + send +
	// reading the entire body), enforced as a context deadline. 0 disables it.
	RequestTimeoutMs int `json:"rt" msgpack:"rt"`
	// ConnectTimeoutMs bounds establishing the TCP/TLS connection (net.Dialer).
	ConnectTimeoutMs int `json:"ct" msgpack:"ct"`
	// ResponseHeaderTimeoutMs bounds waiting for the status line and headers.
	ResponseHeaderTimeoutMs int `json:"rht" msgpack:"rht"`
	// MaxResponseBody caps the response body in bytes; 0 means unlimited.
	MaxResponseBody int64 `json:"mrb" msgpack:"mrb"`
	FollowRedirects bool  `json:"fr" msgpack:"fr"`
	MaxRedirects    int   `json:"mr" msgpack:"mr"`
	// ChunkSize is the granularity of reading the response body (inline first chunk
	// and each streamed chunk).
	ChunkSize int `json:"cs" msgpack:"cs"`
	// VerifyTls toggles TLS certificate verification (off for self-signed in dev).
	VerifyTls bool `json:"vt" msgpack:"vt"`
	// Connection-pool tuning, supplied by the PHP side (its defaults mirror Go's).
	MaxIdleConns          int `json:"mic" msgpack:"mic"`
	MaxIdleConnsPerHost   int `json:"mih" msgpack:"mih"`
	IdleConnTimeoutMs     int `json:"ict" msgpack:"ict"`
	TLSHandshakeTimeoutMs int `json:"tht" msgpack:"tht"`
}

// ResponseMeta is the first result the client emits for a request: the response
// status, headers and the inline first chunk of the body. Subsequent results
// (pulled via next) are raw body chunks, not this struct. ContentLength is the
// response Content-Length, or -1 when unknown (e.g. chunked transfer).
// PHP: decoded in SConcur\Features\HttpClient\HttpClient::sendRequest.
type ResponseMeta struct {
	Status        int                 `json:"st" msgpack:"st"`
	Headers       map[string][]string `json:"hd" msgpack:"hd"`
	Body          string              `json:"b" msgpack:"b"`
	ContentLength int64               `json:"cl" msgpack:"cl"`
}

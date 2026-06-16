// Package payloads holds the Go counterparts of the PHP HTTP-client payload
// objects (SConcur\Features\HttpClient\Payloads\*) and the response metadata the
// client streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
//
// Every message is a command envelope (cm/p) under MethodHttpClient — mirrors the
// MongoDB feature: cm selects the sub-operation, p carries that command's
// parameters (decoded into RequestParams / UploadParams).
package payloads

import "github.com/vmihailenco/msgpack/v5"

// Envelope is the command envelope decoded from the msgpack message.
// PHP: SConcur\Features\HttpClient\Payloads\Base\BaseHttpClientPayload (cm from the
// command enum, p from the parameters' getData()).
type Envelope struct {
	Command int                `json:"cm" msgpack:"cm"`
	Params  msgpack.RawMessage `json:"p" msgpack:"p"`
}

// RequestParams is the `p` content of a Request command — one request to send, plus
// the per-request tuning (timeouts in milliseconds, body limit in bytes). Defaults
// are supplied by the PHP side (HttpClientOptions). RequestTimeoutMs carries the
// mandatory hard execution limit for the whole operation.
// PHP: SConcur\Features\HttpClient\Payloads\RequestPayloadParameters.
type RequestParams struct {
	Method  string              `json:"m" msgpack:"m"`
	Url     string              `json:"u" msgpack:"u"`
	Headers map[string][]string `json:"h" msgpack:"h"`
	Body    string              `json:"b" msgpack:"b"`
	// StreamBody opens the request with a piped body that PHP fills via UploadPayload
	// commands (chunked upload). When false, Body holds the whole buffered body.
	StreamBody bool `json:"sb" msgpack:"sb"`
	// RequestId keys the open request so its upload chunks find it (set when StreamBody).
	RequestId string `json:"rid" msgpack:"rid"`
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

// UploadParams is the `p` content of an UploadChunk/UploadEnd command: the request
// being uploaded to (RequestId) and, for a chunk, the bytes to append (empty for
// the end marker; the command itself distinguishes chunk from end).
// PHP: SConcur\Features\HttpClient\Payloads\UploadPayloadParameters.
type UploadParams struct {
	RequestId string `json:"rid" msgpack:"rid"`
	Body      string `json:"b" msgpack:"b"`
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

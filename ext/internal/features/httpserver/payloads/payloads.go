// Package payloads holds the Go counterparts of the PHP HTTP-server payload
// objects (SConcur\Features\HttpServer\Payloads\*) and the request event the
// server streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
package payloads

// ServePayload is the payload of an httpStart command.
// PHP: SConcur\Features\HttpServer\Payloads\ServePayload.
type ServePayload struct {
	Address string `json:"ad" msgpack:"ad"`
}

// RespondPayload is the payload of an httpRespond command — the response a PHP
// request-handler coroutine sends back for a given request.
// PHP: SConcur\Features\HttpServer\Payloads\RespondPayload.
type RespondPayload struct {
	RequestId string            `json:"rid" msgpack:"rid"`
	Status    int               `json:"st" msgpack:"st"`
	Headers   map[string]string `json:"hd" msgpack:"hd"`
	Body      string            `json:"bd" msgpack:"bd"`
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
	Body      string              `json:"bd" msgpack:"bd"`
}

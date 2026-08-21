package types

import "strings"

type Method string

const (
	MethodSleep       Method = "sl"
	MethodMongodb     Method = "mng"
	MethodHttpServe   Method = "hs"
	MethodHttpRespond Method = "hr"
	MethodHttpClient  Method = "hc"
	MethodMysql       Method = "my"
	MethodPgsql       Method = "pg"
	MethodSocketServe   Method = "ss"
	MethodSocketRespond Method = "sr"
	MethodSocketClient  Method = "sc"
	MethodWsServe    Method = "wss"
	MethodWsRespond  Method = "wsr"
	MethodWsClient   Method = "wsc"

	MethodAmqp Method = "amq"
)

// internedMethods maps every known method onto its canonical constant. A map
// lookup keyed by a temporary string view does not retain the view, so the
// boundary can resolve a method without first copying the C bytes.
var internedMethods = map[Method]Method{
	MethodSleep:         MethodSleep,
	MethodMongodb:       MethodMongodb,
	MethodHttpServe:     MethodHttpServe,
	MethodHttpRespond:   MethodHttpRespond,
	MethodHttpClient:    MethodHttpClient,
	MethodMysql:         MethodMysql,
	MethodPgsql:         MethodPgsql,
	MethodSocketServe:   MethodSocketServe,
	MethodSocketRespond: MethodSocketRespond,
	MethodSocketClient:  MethodSocketClient,
	MethodWsServe:       MethodWsServe,
	MethodWsRespond:     MethodWsRespond,
	MethodWsClient:      MethodWsClient,
	MethodAmqp:          MethodAmqp,
}

// InternMethod returns the canonical instance of a known method, so a caller
// holding only a temporary view of the bytes (a cgo buffer that dies with the
// call) can store the result without allocating a copy per message. An unknown
// method is cloned — the only case that still allocates.
func InternMethod(method Method) Method {
	if canonical, ok := internedMethods[method]; ok {
		return canonical
	}

	return Method(strings.Clone(string(method)))
}

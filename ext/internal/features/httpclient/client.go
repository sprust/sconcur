package httpclient_feature

import (
	"crypto/tls"
	"errors"
	"net"
	"net/http"
	"sync"
	"time"
)

// Connection-pool fallbacks, used only when the PHP side sends a zero value (the
// PHP defaults normally supply these and mirror them).
const (
	defaultMaxIdleConns        = 100
	defaultMaxIdleConnsPerHost = 16
	defaultIdleConnTimeout     = 90 * time.Second
	defaultTLSHandshakeTimeout = 10 * time.Second
)

// errTooManyRedirects is returned by the redirect policy once the configured
// maximum is exceeded. Surfaces to PHP as a network-class error.
var errTooManyRedirects = errors.New("too many redirects")

// transportKey identifies a reusable *http.Transport by the request options that
// affect the transport itself. Requests sharing a key share a connection pool
// (keep-alive); options that only affect a single request (redirect policy, the
// overall deadline) live on the per-request *http.Client instead.
type transportKey struct {
	connectTimeoutMs        int
	responseHeaderTimeoutMs int
	verifyTls               bool
	maxIdleConns            int
	maxIdleConnsPerHost     int
	idleConnTimeoutMs       int
	tlsHandshakeTimeoutMs   int
}

var (
	transportsMutex sync.Mutex
	transportsCache = map[transportKey]*http.Transport{}
)

// getTransport returns the shared transport for the given key, building it once.
// Keeping transports per distinct config preserves keep-alive/pooling between
// requests while still honoring per-request connect/header timeouts and TLS mode.
func getTransport(key transportKey) *http.Transport {
	transportsMutex.Lock()
	defer transportsMutex.Unlock()

	if transport, ok := transportsCache[key]; ok {
		return transport
	}

	dialer := &net.Dialer{
		KeepAlive: 30 * time.Second,
	}

	if key.connectTimeoutMs > 0 {
		dialer.Timeout = time.Duration(key.connectTimeoutMs) * time.Millisecond
	}

	transport := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           dialer.DialContext,
		ForceAttemptHTTP2:     false, // v1 is HTTP/1.1; h2 is out of scope.
		MaxIdleConns:          intOrDefault(key.maxIdleConns, defaultMaxIdleConns),
		MaxIdleConnsPerHost:   intOrDefault(key.maxIdleConnsPerHost, defaultMaxIdleConnsPerHost),
		IdleConnTimeout:       msOrDefault(key.idleConnTimeoutMs, defaultIdleConnTimeout),
		TLSHandshakeTimeout:   msOrDefault(key.tlsHandshakeTimeoutMs, defaultTLSHandshakeTimeout),
		ExpectContinueTimeout: 1 * time.Second,
	}

	if key.responseHeaderTimeoutMs > 0 {
		transport.ResponseHeaderTimeout = time.Duration(key.responseHeaderTimeoutMs) * time.Millisecond
	}

	if !key.verifyTls {
		transport.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
	}

	transportsCache[key] = transport

	return transport
}

// buildClient assembles the *http.Client for one request. The transport (and its
// pool) is shared per transportKey; the redirect policy is per request. The
// overall deadline is enforced via the request context (see feature.go), not
// Client.Timeout, so it also covers reading the streamed body.
func buildClient(payloadKey transportKey, followRedirects bool, maxRedirects int) *http.Client {
	return &http.Client{
		Transport:     getTransport(payloadKey),
		CheckRedirect: redirectPolicy(followRedirects, maxRedirects),
	}
}

// redirectPolicy builds the http.Client CheckRedirect callback: stop following
// (return the last response as-is) when redirects are disabled, otherwise cap the
// number of hops.
func redirectPolicy(followRedirects bool, maxRedirects int) func(request *http.Request, via []*http.Request) error {
	if !followRedirects {
		return func(request *http.Request, via []*http.Request) error {
			return http.ErrUseLastResponse
		}
	}

	return func(request *http.Request, via []*http.Request) error {
		if maxRedirects > 0 && len(via) >= maxRedirects {
			return errTooManyRedirects
		}

		return nil
	}
}

func intOrDefault(value int, fallback int) int {
	if value <= 0 {
		return fallback
	}

	return value
}

func msOrDefault(ms int, fallback time.Duration) time.Duration {
	if ms <= 0 {
		return fallback
	}

	return time.Duration(ms) * time.Millisecond
}

// CloseIdleConnections closes idle keep-alive connections on every shared
// transport. Called from features.Shutdown alongside the MongoDB clients.
func CloseIdleConnections() {
	transportsMutex.Lock()
	defer transportsMutex.Unlock()

	for _, transport := range transportsCache {
		transport.CloseIdleConnections()
	}
}

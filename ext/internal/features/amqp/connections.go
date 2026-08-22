package amqp_feature

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"net"
	"os"
	"sconcur/internal/features/amqp/payloads"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

const (
	// defaultConnectTimeout bounds the dial when the credentials name no connect_timeout.
	defaultConnectTimeout = 10 * time.Second
	// defaultRpcTimeout bounds one broker method when the credentials name no
	// rpc_timeout: a task must never run unbounded.
	defaultRpcTimeout = 30 * time.Second
	// defaultWriteTimeout bounds one publish when the credentials name no write_timeout.
	defaultWriteTimeout = 30 * time.Second

	// connectionIdleTTL: a pooled connection nothing holds any more is closed after
	// staying idle this long, mirroring the MongoDB and SQL pools.
	connectionIdleTTL       = 5 * time.Minute
	connectionSweepInterval = time.Minute
	connectionCloseTimeout  = 5 * time.Second

	// saslMethodExternal mirrors AMQP_SASL_METHOD_EXTERNAL: authenticate with the TLS
	// client certificate instead of a login and a password.
	saslMethodExternal = 1
)

// connectionKey identifies a pooled connection. A comparable struct is the key itself, so
// acquiring one builds no string.
//
// It holds what the broker sees of a connection: the address, the credentials, the TLS
// material and everything the handshake settles on. The connect timeout is deliberately
// not part of it — it bounds the dial and nothing beyond it, so two AMQPConnection objects
// that differ only there share one connection, which is what the feature promises for the
// same credentials (docs/amqp.md).
type connectionKey struct {
	host             string
	port             int
	vhost            string
	login            string
	password         string
	caCertPath       string
	certPath         string
	keyPath          string
	verify           bool
	saslMethod       int
	connectionName   string
	channelMax       int
	frameMaxBytes    int
	heartbeatSeconds int
}

// pooledConnection is one live connection to the broker plus the owner count that keeps
// it from being swept while PHP still holds a handle on it.
type pooledConnection struct {
	connection   *amqp091.Connection
	key          connectionKey
	maxChannels  int
	maxFrameSize int
	heartbeat    int
	inUse        int
	lastUsedAt   time.Time
}

// connectionHandle is what one PHP AMQPConnection holds: a share of a pooled connection
// and the channels opened through it.
type connectionHandle struct {
	id     string
	pooled *pooledConnection

	mutex sync.Mutex
	// channelCounter numbers the channels of this handle; it only ever grows, so a
	// closed channel never hands its number to the next one.
	channelCounter int
	channels       map[string]*channelEntry
	closed         bool
}

var connectionsOnce sync.Once
var connectionsInstance *connections

// connectionsCreated tells Shutdown whether this process ever opened a connection, so it
// does not build the registry just to close an empty one.
var connectionsCreated atomic.Bool

// handleCounter backs the connection handle ids.
var handleCounter atomic.Int64

type connections struct {
	mutex sync.Mutex
	pool  map[connectionKey]*pooledConnection

	handlesMutex sync.RWMutex
	handles      map[string]*connectionHandle
}

func getConnections() *connections {
	connectionsOnce.Do(func() {
		connectionsInstance = &connections{
			pool:    make(map[connectionKey]*pooledConnection),
			handles: make(map[string]*connectionHandle),
		}

		connectionsInstance.startSweeper()

		connectionsCreated.Store(true)
	})

	return connectionsInstance
}

// open hands out a handle on a connection matching the parameters, dialing one if the
// pool holds none. The dial happens outside the lock, so connecting to a slow broker does
// not stall every other connect.
func (c *connections) open(ctx context.Context, params payloads.ConnectParams) (*connectionHandle, error) {
	key := connectionKeyFromParams(params)

	pooled := c.take(key)

	if pooled == nil {
		connection, err := dial(ctx, params, key)

		if err != nil {
			return nil, err
		}

		pooled = c.store(key, connection)
	}

	handle := &connectionHandle{
		id:       nextHandleId(),
		pooled:   pooled,
		channels: make(map[string]*channelEntry),
	}

	c.handlesMutex.Lock()
	c.handles[handle.id] = handle
	c.handlesMutex.Unlock()

	return handle, nil
}

// take returns a pooled connection for the key, already marked as held, or nil when the
// pool has none that is still alive.
func (c *connections) take(key connectionKey) *pooledConnection {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	pooled, exists := c.pool[key]

	if !exists {
		return nil
	}

	if pooled.connection.IsClosed() {
		delete(c.pool, key)

		return nil
	}

	pooled.inUse++
	pooled.lastUsedAt = time.Now()

	return pooled
}

// store puts a freshly dialed connection into the pool and marks it held. If another
// connect won the race for the same key, the newcomer is closed and the winner is used —
// so the pool never holds two connections for one key.
func (c *connections) store(key connectionKey, connection *amqp091.Connection) *pooledConnection {
	c.mutex.Lock()

	existing, exists := c.pool[key]

	if exists && !existing.connection.IsClosed() {
		existing.inUse++
		existing.lastUsedAt = time.Now()

		c.mutex.Unlock()

		_ = connection.Close()

		return existing
	}

	pooled := &pooledConnection{
		connection:   connection,
		key:          key,
		maxChannels:  int(connection.Config.ChannelMax),
		maxFrameSize: connection.Config.FrameSize,
		heartbeat:    int(connection.Config.Heartbeat / time.Second),
		inUse:        1,
		lastUsedAt:   time.Now(),
	}

	c.pool[key] = pooled

	c.mutex.Unlock()

	c.watch(pooled)

	return pooled
}

// watch drops a connection from the pool as soon as the broker or the network ends it,
// and clears the channels that were open on it — their handles would otherwise hand out
// ids nothing answers to.
func (c *connections) watch(pooled *pooledConnection) {
	closed := make(chan *amqp091.Error, 1)

	pooled.connection.NotifyClose(closed)

	go func() {
		<-closed

		c.mutex.Lock()

		if current, exists := c.pool[pooled.key]; exists && current == pooled {
			delete(c.pool, pooled.key)
		}

		c.mutex.Unlock()

		c.dropChannelsOf(pooled)
	}()
}

// dropChannelsOf removes every channel registered on a dead connection.
func (c *connections) dropChannelsOf(pooled *pooledConnection) {
	c.handlesMutex.RLock()

	handles := make([]*connectionHandle, 0, len(c.handles))

	for _, handle := range c.handles {
		if handle.pooled == pooled {
			handles = append(handles, handle)
		}
	}

	c.handlesMutex.RUnlock()

	for _, handle := range handles {
		getChannels().dropHandle(handle)
	}
}

func (c *connections) find(connectionId string) *connectionHandle {
	c.handlesMutex.RLock()
	defer c.handlesMutex.RUnlock()

	return c.handles[connectionId]
}

// release drops a handle: its channels are closed and the share it held on the pooled
// connection is given back, so the connection can be swept once nothing holds it.
func (c *connections) release(connectionId string) {
	c.handlesMutex.Lock()

	handle, exists := c.handles[connectionId]

	delete(c.handles, connectionId)

	c.handlesMutex.Unlock()

	if !exists {
		return
	}

	handle.mutex.Lock()
	handle.closed = true
	handle.mutex.Unlock()

	getChannels().dropHandle(handle)

	c.mutex.Lock()

	if handle.pooled.inUse > 0 {
		handle.pooled.inUse--
	}

	handle.pooled.lastUsedAt = time.Now()

	c.mutex.Unlock()
}

func (c *connections) startSweeper() {
	go func() {
		ticker := time.NewTicker(connectionSweepInterval)
		defer ticker.Stop()

		for range ticker.C {
			c.sweep()
		}
	}()
}

func (c *connections) sweep() {
	for _, pooled := range c.collectExpired(time.Now()) {
		closeConnection(pooled.connection)
	}
}

// collectExpired removes and returns the pooled connections nothing holds any more and
// nothing has touched for longer than the TTL. Closing is left to the caller, outside the
// lock.
func (c *connections) collectExpired(now time.Time) []*pooledConnection {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	var expired []*pooledConnection

	for key, pooled := range c.pool {
		if pooled.inUse == 0 && now.Sub(pooled.lastUsedAt) > connectionIdleTTL {
			expired = append(expired, pooled)

			delete(c.pool, key)
		}
	}

	return expired
}

// closeAll tears down every connection and channel: called from features.Shutdown.
func (c *connections) closeAll() {
	c.handlesMutex.Lock()

	handles := c.handles
	c.handles = make(map[string]*connectionHandle)

	c.handlesMutex.Unlock()

	for _, handle := range handles {
		getChannels().dropHandle(handle)
	}

	c.mutex.Lock()

	pool := c.pool
	c.pool = make(map[connectionKey]*pooledConnection)

	c.mutex.Unlock()

	for _, pooled := range pool {
		closeConnection(pooled.connection)
	}
}

// dial opens one connection, bounded by the connect timeout and abandoned if the flow
// stops meanwhile — the amqp091 dial takes no context, so it runs on its own goroutine
// and a connection that arrives late is closed rather than leaked.
func dial(ctx context.Context, params payloads.ConnectParams, key connectionKey) (*amqp091.Connection, error) {
	config, err := dialConfig(params)

	if err != nil {
		return nil, err
	}

	type dialed struct {
		connection *amqp091.Connection
		err        error
	}

	results := make(chan dialed, 1)

	uri := connectionUri(key)

	go func() {
		connection, err := amqp091.DialConfig(uri, config)

		results <- dialed{connection: connection, err: err}
	}()

	select {
	case result := <-results:
		return result.connection, result.err
	case <-ctx.Done():
		go func() {
			result := <-results

			if result.connection != nil {
				_ = result.connection.Close()
			}
		}()

		return nil, ctx.Err()
	}
}

func dialConfig(params payloads.ConnectParams) (amqp091.Config, error) {
	connectTimeout := msOrDefault(params.ConnectTimeoutMs, defaultConnectTimeout)

	config := amqp091.Config{
		Vhost:      params.Vhost,
		ChannelMax: uint16(max(params.ChannelMax, 0)),
		FrameSize:  params.FrameMaxBytes,
		Heartbeat:  time.Duration(max(params.HeartbeatSeconds, 0)) * time.Second,
		Dial:       amqp091.DefaultDial(connectTimeout),
		Properties: amqp091.NewConnectionProperties(),
		// The credentials are handed over as they are. The driver would otherwise
		// derive them by parsing the URI, which mangles every login or password
		// holding a character the URI syntax reserves: "%" starts an escape, "/",
		// "?" and "#" end the userinfo, and ":" splits it.
		SASL: []amqp091.Authentication{
			&amqp091.PlainAuth{
				Username: params.Login,
				Password: params.Password,
			},
		},
	}

	if params.ConnectionName != "" {
		config.Properties.SetClientConnectionName(params.ConnectionName)
	}

	if params.SaslMethod == saslMethodExternal {
		config.SASL = []amqp091.Authentication{&amqp091.ExternalAuth{}}
	}

	if !usesTls(params) {
		return config, nil
	}

	tlsConfig, err := tlsConfigFromParams(params)

	if err != nil {
		return config, err
	}

	config.TLSClientConfig = tlsConfig

	return config, nil
}

func usesTls(params payloads.ConnectParams) bool {
	return params.CaCertPath != "" || params.CertPath != "" || params.KeyPath != ""
}

func tlsConfigFromParams(params payloads.ConnectParams) (*tls.Config, error) {
	config := &tls.Config{
		ServerName:         params.Host,
		InsecureSkipVerify: !params.Verify,
	}

	if params.CaCertPath != "" {
		authority, err := os.ReadFile(params.CaCertPath)

		if err != nil {
			return nil, err
		}

		pool := x509.NewCertPool()

		if !pool.AppendCertsFromPEM(authority) {
			return nil, errors.New("could not read the CA certificate " + params.CaCertPath)
		}

		config.RootCAs = pool
	}

	if params.CertPath == "" && params.KeyPath == "" {
		return config, nil
	}

	certificate, err := tls.LoadX509KeyPair(params.CertPath, params.KeyPath)

	if err != nil {
		return nil, err
	}

	config.Certificates = []tls.Certificate{certificate}

	return config, nil
}

// connectionUri builds the amqp:// (or amqps://) URI of a connection: the address and
// nothing else. The credentials travel in the dial config (dialConfig) and the vhost in
// its Vhost field, so nothing here has to survive URI escaping.
func connectionUri(key connectionKey) string {
	scheme := "amqp://"

	if key.caCertPath != "" || key.certPath != "" || key.keyPath != "" {
		scheme = "amqps://"
	}

	return scheme + net.JoinHostPort(key.host, strconv.Itoa(key.port)) + "/"
}

func connectionKeyFromParams(params payloads.ConnectParams) connectionKey {
	return connectionKey{
		host:             params.Host,
		port:             params.Port,
		vhost:            params.Vhost,
		login:            params.Login,
		password:         params.Password,
		caCertPath:       params.CaCertPath,
		certPath:         params.CertPath,
		keyPath:          params.KeyPath,
		verify:           params.Verify,
		saslMethod:       params.SaslMethod,
		connectionName:   params.ConnectionName,
		channelMax:       params.ChannelMax,
		frameMaxBytes:    params.FrameMaxBytes,
		heartbeatSeconds: params.HeartbeatSeconds,
	}
}

func nextHandleId() string {
	return "amqp:c:" + strconv.FormatInt(handleCounter.Add(1), 10)
}

// closeConnection closes a connection under a fresh deadline: whatever triggered the
// close may already be cancelled.
func closeConnection(connection *amqp091.Connection) {
	done := make(chan struct{})

	go func() {
		_ = connection.Close()

		close(done)
	}()

	ctx, cancel := context.WithTimeout(context.Background(), connectionCloseTimeout)
	defer cancel()

	select {
	case <-done:
	case <-ctx.Done():
	}
}

func msOrDefault(milliseconds int, fallback time.Duration) time.Duration {
	if milliseconds <= 0 {
		return fallback
	}

	return time.Duration(milliseconds) * time.Millisecond
}

package amqp_feature

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"net"
	"os"
	"strconv"
	"time"

	"sconcur/internal/features/amqp/payloads"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

// dial opens one connection, bounded by the connect timeout and abandoned if the flow
// stops meanwhile — the amqp091 dial takes no context, so it runs on its own goroutine
// and a connection that arrives late is closed rather than leaked.
func dial(ctx context.Context, params payloads.ConnectParams, key connectionKey) (*amqp091.Connection, error) {
	config, err := dialConfig(params)

	if err != nil {
		return nil, err
	}

	uri := connectionUri(key)

	return boundedResult(
		ctx,
		func() (*amqp091.Connection, error) {
			return amqp091.DialConfig(uri, config)
		},
		func(connection *amqp091.Connection) {
			_ = connection.Close()
		},
	)
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

	if !secureDial(params.Secure, params.CaCertPath, params.CertPath, params.KeyPath) {
		return config, nil
	}

	tlsConfig, err := tlsConfigFromParams(params)

	if err != nil {
		return config, err
	}

	config.TLSClientConfig = tlsConfig

	return config, nil
}

// secureDial answers whether to speak TLS. The flag is what the caller asked for and is
// decisive on its own: a connection with no certificate paths — the system trust store, or
// verification turned off against a development broker — is still a TLS connection, and
// inferring otherwise would put the login and password on the wire in the clear.
//
// The paths are still honoured for their own sake, so material named without the flag
// cannot be silently ignored either.
func secureDial(secure bool, caCertPath string, certPath string, keyPath string) bool {
	return secure || caCertPath != "" || certPath != "" || keyPath != ""
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

// connectionUri builds what the driver dials: the address and nothing else. The
// credentials travel in the dial config (dialConfig) and the vhost in its Vhost field, so
// nothing here has to survive URI escaping.
//
// The scheme has to agree with the TLS config dialConfig built, so both read it off the
// same predicate.
func connectionUri(key connectionKey) string {
	scheme := "amqp://"

	if secureDial(key.secure, key.caCertPath, key.certPath, key.keyPath) {
		scheme = "amqps://"
	}

	return scheme + net.JoinHostPort(key.host, strconv.Itoa(key.port)) + "/"
}

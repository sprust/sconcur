package amqp_feature

import (
	"context"
	"errors"
	"sconcur/internal/features/amqp/payloads"
	"strings"
	"testing"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

// newTestConnections builds an isolated registry (not the process singleton) so a test
// starts no sweeper goroutine and does not disturb its neighbours.
func newTestConnections() *connections {
	return &connections{
		pool:    make(map[connectionKey]*pooledConnection),
		handles: make(map[string]*connectionHandle),
	}
}

func newTestChannels() *channels {
	return &channels{
		entries: make(map[string]*channelEntry),
	}
}

func TestConnectionKeyTellsCredentialsApart(t *testing.T) {
	base := payloads.ConnectParams{
		Host:     "broker",
		Port:     5672,
		Vhost:    "/",
		Login:    "user",
		Password: "secret",
	}

	same := connectionKeyFromParams(base)

	if connectionKeyFromParams(base) != same {
		t.Fatal("the same credentials must produce the same key")
	}

	otherVhost := base
	otherVhost.Vhost = "/other"

	if connectionKeyFromParams(otherVhost) == same {
		t.Fatal("a different vhost must produce a different key")
	}

	otherTuning := base
	otherTuning.HeartbeatSeconds = 30

	if connectionKeyFromParams(otherTuning) == same {
		t.Fatal("different tuning must produce a different key")
	}
}

func TestIdleConnectionsAreSweptOnlyWhenNothingHoldsThem(t *testing.T) {
	registry := newTestConnections()

	key := connectionKeyFromParams(payloads.ConnectParams{Host: "broker", Port: 5672})

	held := &pooledConnection{
		key:        key,
		inUse:      1,
		lastUsedAt: time.Now().Add(-2 * connectionIdleTTL),
	}

	registry.pool[key] = held

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a held connection must survive the sweep, got %d expired", len(expired))
	}

	held.inUse = 0

	expired := registry.collectExpired(time.Now())

	if len(expired) != 1 || expired[0] != held {
		t.Fatalf("an idle unheld connection must be swept, got %d expired", len(expired))
	}

	if len(registry.pool) != 0 {
		t.Fatal("a swept connection must be removed from the pool")
	}
}

func TestAFreshlyUsedConnectionIsNotSwept(t *testing.T) {
	registry := newTestConnections()

	key := connectionKeyFromParams(payloads.ConnectParams{Host: "broker", Port: 5672})

	registry.pool[key] = &pooledConnection{
		key:        key,
		inUse:      0,
		lastUsedAt: time.Now(),
	}

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a connection used just now must survive the sweep, got %d expired", len(expired))
	}
}

func TestIdleChannelsAreSweptOnlyWithoutConsumers(t *testing.T) {
	registry := newTestChannels()

	idle := &channelEntry{
		id:         "amqp:ch:1",
		consumers:  make(map[string]struct{}),
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	consuming := &channelEntry{
		id: "amqp:ch:2",
		consumers: map[string]struct{}{
			"ctag-1": {},
		},
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	registry.entries[idle.id] = idle
	registry.entries[consuming.id] = consuming

	expired := registry.collectExpired(time.Now())

	if len(expired) != 1 || expired[0] != idle {
		t.Fatalf("only the channel with no consumers must be swept, got %d expired", len(expired))
	}

	if _, exists := registry.entries[consuming.id]; !exists {
		t.Fatal("a channel with a consumer must stay in the registry")
	}
}

func TestFindingAChannelKeepsItFromTheSweeper(t *testing.T) {
	registry := newTestChannels()

	entry := &channelEntry{
		id:         "amqp:ch:1",
		consumers:  make(map[string]struct{}),
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	registry.entries[entry.id] = entry

	if _, err := registry.find(entry.id); err != nil {
		t.Fatalf("find error: %v", err)
	}

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a channel a command just used must survive the sweep, got %d expired", len(expired))
	}

	if _, err := registry.find("amqp:ch:missing"); err == nil {
		t.Fatal("an unknown channel id must be reported")
	}
}

func TestTheCredentialsNeverTravelThroughTheUri(t *testing.T) {
	params := payloads.ConnectParams{
		Host:     "broker",
		Port:     5672,
		Vhost:    "/",
		Login:    "us:er",
		Password: "pa%73s/word#",
	}

	uri := connectionUri(connectionKeyFromParams(params))

	// Anything in the userinfo would be re-parsed by the driver, which decodes percent
	// escapes and stops the userinfo at the first "/", "?" or "#".
	if strings.Contains(uri, "@") || strings.Contains(uri, params.Password) {
		t.Fatalf("uri = %q, want no credentials in it", uri)
	}

	config, err := dialConfig(params)

	if err != nil {
		t.Fatalf("dialConfig error: %v", err)
	}

	if len(config.SASL) != 1 {
		t.Fatalf("SASL = %#v, want exactly one mechanism", config.SASL)
	}

	plain, ok := config.SASL[0].(*amqp091.PlainAuth)

	if !ok {
		t.Fatalf("SASL[0] = %T, want *amqp091.PlainAuth", config.SASL[0])
	}

	if plain.Username != params.Login || plain.Password != params.Password {
		t.Fatalf("credentials = %q/%q, want them verbatim", plain.Username, plain.Password)
	}
}

func TestTheExternalMechanismIsUsedWhenAsked(t *testing.T) {
	config, err := dialConfig(payloads.ConnectParams{
		Host:       "broker",
		Port:       5671,
		SaslMethod: saslMethodExternal,
	})

	if err != nil {
		t.Fatalf("dialConfig error: %v", err)
	}

	if _, ok := config.SASL[0].(*amqp091.ExternalAuth); !ok {
		t.Fatalf("SASL[0] = %T, want *amqp091.ExternalAuth", config.SASL[0])
	}
}

func TestAFailedPublishGivesBackItsPendingConfirm(t *testing.T) {
	entry := newTestEntry()

	entry.confirming = true

	entry.publishing()
	entry.publishing()
	entry.publishFailed()

	if entry.pending != 1 {
		t.Fatalf("pending = %d, want 1", entry.pending)
	}

	// A wait loop must not be left counting on a confirmation that will never come.
	entry.publishFailed()

	if entry.pending != 0 {
		t.Fatalf("pending = %d, want 0", entry.pending)
	}
}

func TestAWaitEndsWhenTheChannelGoesAway(t *testing.T) {
	entry := newTestEntry()

	entry.confirming = true
	entry.pending = 1

	go func() {
		close(entry.gone)
	}()

	// No deadline: the wait would sit here for the life of the process if a dead channel
	// did not end it.
	_, err := entry.waitForConfirms(context.Background(), 0)

	if !errors.Is(err, amqp091.ErrClosed) {
		t.Fatalf("err = %v, want the channel-closed error", err)
	}
}

func TestAWaitEndsOnItsDeadline(t *testing.T) {
	entry := newTestEntry()

	// Never put into confirm mode: there is nothing to wait for, and ext-amqp answers
	// that with its timeout, not with a quiet success.
	_, err := entry.waitForConfirms(context.Background(), 20*time.Millisecond)

	if err == nil || err.Error() != "Wait timeout exceed" {
		t.Fatalf("err = %v, want the timeout the extension reports", err)
	}
}

func TestWaitingForReturnsLeavesTheConfirmsAlone(t *testing.T) {
	entry := newTestEntry()

	entry.confirmations = []payloads.Confirmation{{DeliveryTag: 1, Acked: true}}
	entry.returns = []payloads.ReturnedMessage{{ReplyCode: 312}}

	result, err := entry.waitForReturns(context.Background(), time.Second)

	if err != nil {
		t.Fatalf("waitForReturns error: %v", err)
	}

	if len(result.Returns) != 1 || len(result.Confirmations) != 0 {
		t.Fatalf("result = %#v, want the returns only", result)
	}

	// Taking them here would strand the wait loop that is counting on them.
	if len(entry.confirmations) != 1 {
		t.Fatalf("confirmations left = %d, want 1", len(entry.confirmations))
	}
}

// newTestEntry builds a channel entry with no driver channel behind it: enough for the
// bookkeeping the wait loops and the confirm accounting do.
func newTestEntry() *channelEntry {
	return &channelEntry{
		id:        "amqp:ch:test",
		consumers: make(map[string]struct{}),
		gone:      make(chan struct{}),
	}
}

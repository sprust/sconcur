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

	idle := newChannelEntry("amqp:ch:1", nil, nil)
	idle.lastUsedAt = time.Now().Add(-2 * channelIdleTTL)

	consuming := newChannelEntry("amqp:ch:2", nil, nil)
	consuming.consumers["ctag-1"] = "flow:1"
	consuming.lastUsedAt = time.Now().Add(-2 * channelIdleTTL)

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

	entry := newChannelEntry("amqp:ch:1", nil, nil)
	entry.lastUsedAt = time.Now().Add(-2 * channelIdleTTL)

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

	if err == nil || err.Error() != errWaitTimeout.Error() {
		t.Fatalf("err = %v, want the timeout the extension reports", err)
	}
}

// A mandatory message that routed nowhere is returned AND acknowledged, so a publisher
// waiting for its confirm has to be handed both in one drain — reading the confirmations
// alone would report a success for a message that reached no queue.
// A wait that runs out of time is a command failure with no reply code, which is what
// makes PHP raise it as PublishConfirmTimeoutException rather than as a dead channel.
func TestAWaitTimeoutIsScopedAsACommandFailure(t *testing.T) {
	scope, code, message := classify(nil, "confirm wait", errWaitTimeout)

	if scope != scopeCommand {
		t.Fatalf("scope = %q, want %q", scope, scopeCommand)
	}

	if code != 0 {
		t.Fatalf("code = %d, want 0 — a deadline is not the broker refusing anything", code)
	}

	if message != errWaitTimeout.Error() {
		t.Fatalf("message = %q, want %q", message, errWaitTimeout.Error())
	}
}

// A command that outran the deadline PHP gave it is a command failure. context.Cancelled
// carries no Timeout()/Temporary() pair, but context.DeadlineExceeded does, which makes it
// a net.Error — and reading it as one has PHP mark the connection and every channel on it
// unusable over a single slow declare on a broker that never went anywhere.
func TestACommandDeadlineIsScopedAsACommandFailure(t *testing.T) {
	scope, code, message := classify(newTestEntry(), "queue declare", context.DeadlineExceeded)

	if scope != scopeCommand {
		t.Fatalf("scope = %q, want %q — the connection is alive", scope, scopeCommand)
	}

	if code != 0 {
		t.Fatalf("code = %d, want 0 — the broker refused nothing", code)
	}

	if message != errCommandTimeout.Error() {
		t.Fatalf("message = %q, want %q", message, errCommandTimeout.Error())
	}
}

// The flow stopping is not a timeout and must not read as one.
func TestAStoppedFlowIsScopedAsACommandFailure(t *testing.T) {
	scope, _, _ := classify(newTestEntry(), "queue declare", context.Canceled)

	if scope != scopeCommand {
		t.Fatalf("scope = %q, want %q", scope, scopeCommand)
	}
}

// The driver hands back an already-closed listener when the channel it is registered on is
// gone by then. There is no close reason to record, but the entry still has to leave the
// registry — left there it answers commands, counts towards the connection's channel limit
// and waits half an hour for the idle sweeper.
func TestAChannelGoneBeforeItsListenerWasRegisteredStillLeavesTheRegistry(t *testing.T) {
	registry := getChannels()

	// Marked closed up front so the drop does not go on to the driver: this entry has no
	// channel behind it, and the registry drop is the whole of what is under test.
	entry := newChannelEntry("amqp:ch:closed-before-listening", nil, nil)
	entry.closed = true

	registry.mutex.Lock()
	registry.entries[entry.id] = entry
	registry.mutex.Unlock()

	closed := make(chan *amqp091.Error, 1)

	close(closed)

	go entry.collect(nil, closed)

	for attempt := 0; attempt < 200; attempt++ {
		registry.mutex.RLock()
		_, stillThere := registry.entries[entry.id]
		registry.mutex.RUnlock()

		if !stillThere {
			return
		}

		time.Sleep(time.Millisecond)
	}

	registry.mutex.Lock()
	delete(registry.entries, entry.id)
	registry.mutex.Unlock()

	t.Fatal("the entry stayed in the registry after its channel was reported closed")
}

func TestAConfirmWaitHandsOverTheReturnsWithTheConfirmations(t *testing.T) {
	entry := newTestEntry()

	entry.confirming = true
	entry.confirmations = []payloads.Confirmation{{DeliveryTag: 1, Acked: true}}
	entry.returns = []payloads.ReturnedMessage{{ReplyCode: 312}}

	result, err := entry.waitForConfirms(context.Background(), time.Second)

	if err != nil {
		t.Fatalf("waitForConfirms error: %v", err)
	}

	if len(result.Returns) != 1 || len(result.Confirmations) != 1 {
		t.Fatalf("result = %#v, want both the return and the confirmation", result)
	}

	// The batch is over: a second wait must not report the same message again.
	if len(entry.returns) != 0 || len(entry.confirmations) != 0 {
		t.Fatalf("entry kept %d returns and %d confirmations, want none",
			len(entry.returns), len(entry.confirmations))
	}
}

// newTestEntry builds a channel entry with no driver channel behind it: enough for the
// bookkeeping the wait loops and the confirm accounting do.
func newTestEntry() *channelEntry {
	return newChannelEntry("amqp:ch:test", nil, nil)
}

func TestTheConnectTimeoutDoesNotSplitThePool(t *testing.T) {
	base := payloads.ConnectParams{
		Host:     "broker",
		Port:     5672,
		Vhost:    "/",
		Login:    "user",
		Password: "secret",
	}

	slowDial := base
	slowDial.ConnectTimeoutMs = 30_000

	// It bounds the dial and nothing the broker ever sees, so two Connection objects
	// differing only there must share one connection — which is what the same credentials
	// promise.
	if connectionKeyFromParams(slowDial) != connectionKeyFromParams(base) {
		t.Fatal("the connect timeout must not be part of the pool key")
	}
}

func TestAWaitIsWokenByAnEvent(t *testing.T) {
	entry := newTestEntry()

	entry.confirming = true
	entry.pending = 1

	go func() {
		time.Sleep(10 * time.Millisecond)

		entry.mutex.Lock()
		entry.pending = 0
		entry.confirmations = append(entry.confirmations, payloads.Confirmation{DeliveryTag: 1, Acked: true})
		entry.mutex.Unlock()

		entry.wake()
	}()

	result, err := entry.waitForConfirms(context.Background(), time.Second)

	if err != nil {
		t.Fatalf("err = %v, want the confirmation", err)
	}

	if len(result.Confirmations) != 1 {
		t.Fatalf("confirmations = %d, want 1", len(result.Confirmations))
	}
}

func TestConfirmModeIsNotEnteredTwice(t *testing.T) {
	entry := newTestEntry()

	// The state a confirm select leaves behind when its command deadline passed while the
	// broker was already answering: the driver call went through, so the channel is in
	// confirm mode for good and its collector is running.
	entry.mutex.Lock()
	entry.confirming = true
	entry.mutex.Unlock()

	// A second confirmSelect() must find nothing to do — and must find it without touching
	// the driver channel, which this entry does not have. Registering another listener
	// would count every confirmation twice and fan each one into a buffer nobody reads.
	if err := entry.startConfirmMode(context.Background(), false); err != nil {
		t.Fatalf("err = %v, want a no-op on a channel already in confirm mode", err)
	}
}

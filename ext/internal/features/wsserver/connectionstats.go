package wsserver_feature

import (
	"sconcur/internal/stats"
	"sync/atomic"
)

// connectionStats is the ws server's workload counters: the current open connection
// count and the lifetime number accepted. It implements stats.WorkloadProvider, so
// the shared Collector folds it into each snapshot — the same Connections section the
// socket server reports.
type connectionStats struct {
	active        atomic.Int64
	totalAccepted atomic.Int64
}

// connectionOpened records a newly upgraded connection (bumps active and the lifetime
// total).
func (connectionStats *connectionStats) connectionOpened() {
	connectionStats.active.Add(1)
	connectionStats.totalAccepted.Add(1)
}

// connectionClosed records a connection that has finished (decrements active).
func (connectionStats *connectionStats) connectionClosed() {
	connectionStats.active.Add(-1)
}

// WorkloadSnapshot returns the current connection counters.
func (connectionStats *connectionStats) WorkloadSnapshot() stats.Workload {
	return stats.Workload{
		Connections: &stats.Connections{
			Active:        int(connectionStats.active.Load()),
			TotalAccepted: connectionStats.totalAccepted.Load(),
		},
	}
}

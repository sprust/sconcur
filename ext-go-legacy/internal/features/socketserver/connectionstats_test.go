package socketserver_feature

import "testing"

// TestConnectionStatsCounters checks that active tracks open−closed while
// totalAccepted only ever grows.
func TestConnectionStatsCounters(t *testing.T) {
	connectionStats := &connectionStats{}

	connectionStats.connectionOpened()
	connectionStats.connectionOpened()
	connectionStats.connectionOpened()
	connectionStats.connectionClosed()

	workload := connectionStats.WorkloadSnapshot()
	connections := workload.Connections

	if connections == nil {
		t.Fatal("WorkloadSnapshot returned no connections section")
	}

	if connections.Active != 2 {
		t.Errorf("Active = %d, want 2 (3 opened, 1 closed)", connections.Active)
	}

	if connections.TotalAccepted != 3 {
		t.Errorf("TotalAccepted = %d, want 3 (closing does not decrement it)", connections.TotalAccepted)
	}
}

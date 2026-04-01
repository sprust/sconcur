package transactions

import (
	"database/sql"
	"fmt"
	"sconcur/internal/cleanup"
	"sync"
	"sync/atomic"
)

type entry struct {
	flowKey string
	tx      *sql.Tx
}

type Store struct {
	mutex   sync.Mutex
	counter atomic.Int64
	entries map[string]*entry
}

var once sync.Once
var instance *Store

func GetStore() *Store {
	once.Do(func() {
		instance = &Store{
			entries: make(map[string]*entry),
		}
	})

	return instance
}

func init() {
	cleanup.Register(func(flowKey string) {
		GetStore().RollbackFlow(flowKey)
	})
}

func (s *Store) New(flowKey string, tx *sql.Tx) string {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	id := s.counter.Add(1)
	key := fmt.Sprintf("%s:tx:%d", flowKey, id)

	s.entries[key] = &entry{
		flowKey: flowKey,
		tx:      tx,
	}

	return key
}

func (s *Store) Get(txKey string) (*sql.Tx, bool) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	entry, ok := s.entries[txKey]

	if !ok {
		return nil, false
	}

	return entry.tx, true
}

func (s *Store) Delete(txKey string) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	delete(s.entries, txKey)
}

func (s *Store) RollbackFlow(flowKey string) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	for key, entry := range s.entries {
		if entry.flowKey != flowKey {
			continue
		}

		_ = entry.tx.Rollback()
		delete(s.entries, key)
	}
}

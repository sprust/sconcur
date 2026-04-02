package connection

import (
	"database/sql"
	"sync"
	"time"
)

var once sync.Once
var instance Clients

type Clients struct {
	mutex   sync.RWMutex
	clients map[string]*sql.DB
}

// TODO: max connections

func GetClients() *Clients {
	once.Do(func() {
		instance = Clients{
			clients: make(map[string]*sql.DB),
		}
	})

	return &instance
}

func (c *Clients) GetClient(dsn string) (*sql.DB, error) {
	c.mutex.RLock()
	client, exists := c.clients[dsn]
	c.mutex.RUnlock()

	if exists {
		return client, nil
	}

	db, err := sql.Open("mysql", dsn)

	if err != nil {
		return nil, err
	}

	c.mutex.Lock()
	defer c.mutex.Unlock()

	client, exists = c.clients[dsn]

	if exists {
		_ = db.Close()
		return client, nil
	}

	// TODO: check, fix, update
	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(25)
	db.SetConnMaxLifetime(5 * time.Minute)
	db.SetConnMaxIdleTime(1 * time.Minute)

	c.clients[dsn] = db

	client = db

	return client, nil
}

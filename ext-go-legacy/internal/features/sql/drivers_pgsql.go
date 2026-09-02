package sql_feature

import (
	"sync"

	// Registers the "pgx" driver with database/sql for its side effect.
	_ "github.com/jackc/pgx/v5/stdlib"
)

var pgsqlOnce sync.Once
var pgsqlInstance *SqlFeature

// GetPgsql returns the singleton SQL feature bound to the PostgreSQL driver (pgx).
// Errors are labelled "pgsql" even though the database/sql driver name is "pgx".
func GetPgsql() *SqlFeature {
	pgsqlOnce.Do(func() {
		pgsqlInstance = newSqlFeature("pgx", "pgsql")
	})

	return pgsqlInstance
}

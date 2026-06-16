package sql_feature

import (
	"sync"

	// Registers the "mysql" driver with database/sql for its side effect.
	_ "github.com/go-sql-driver/mysql"
)

var mysqlOnce sync.Once
var mysqlInstance *SqlFeature

// GetMysql returns the singleton SQL feature bound to the MySQL driver.
func GetMysql() *SqlFeature {
	mysqlOnce.Do(func() {
		mysqlInstance = newSqlFeature("mysql")
	})

	return mysqlInstance
}

package types

// SqlCommand selects a sub-operation of the SQL feature, carried in the payload
// envelope's `cm` (like MongodbCommand and HttpClientCommand).
// PHP: SConcur\Features\Sql\SqlCommandEnum.
type SqlCommand int

const (
	SqlQuery    SqlCommand = 1
	SqlExec     SqlCommand = 2
	SqlBegin    SqlCommand = 3
	SqlCommit   SqlCommand = 4
	SqlRollback SqlCommand = 5
)

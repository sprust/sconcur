package types

// SqlCommand selects a sub-operation of the SQL feature, carried in the payload
// envelope's `cm` (like MongodbCommand and HttpClientCommand).
// PHP: SConcur\Features\Sql\SqlCommandEnum.
type SqlCommand string

const (
	SqlQuery    SqlCommand = "qry"
	SqlExec     SqlCommand = "exe"
	SqlBegin    SqlCommand = "beg"
	SqlCommit   SqlCommand = "cmt"
	SqlRollback SqlCommand = "rlb"
)

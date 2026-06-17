// Package payloads holds the Go counterparts of the PHP SQL payload objects
// (SConcur\Features\Sql\Payloads\*). Struct tags are the short keys emitted by the
// PHP getData()/getCommandData() methods; the Go side decodes by msgpack tag.
package payloads

import "github.com/vmihailenco/msgpack/v5"

// Envelope wraps every SQL command: the sub-operation, the connection settings and
// the command body. PHP: SConcur\Features\Sql\Payloads\Base\BaseSqlPayload.
type Envelope struct {
	Command           int                `json:"cm"  msgpack:"cm"`
	Dsn               string             `json:"dsn" msgpack:"dsn"`
	TimeoutMs         int                `json:"to"  msgpack:"to"`
	MaxOpenConns      int                `json:"mo"  msgpack:"mo"`
	MaxIdleConns      int                `json:"mi"  msgpack:"mi"`
	ConnMaxLifetimeMs int                `json:"cl"  msgpack:"cl"`
	Data              msgpack.RawMessage `json:"dt"  msgpack:"dt"`
}

// QueryParams is the body of a Query command (the `dt`).
// PHP: SConcur\Features\Sql\Payloads\QueryPayload.
type QueryParams struct {
	Sql           string `json:"q"  msgpack:"q"`
	Bindings      []any  `json:"b"  msgpack:"b"`
	TransactionId string `json:"tx" msgpack:"tx"`
	BatchSize     int    `json:"bs" msgpack:"bs"`
}

// ExecParams is the body of an Exec command (the `dt`).
// PHP: SConcur\Features\Sql\Payloads\ExecPayload.
type ExecParams struct {
	Sql           string `json:"q"  msgpack:"q"`
	Bindings      []any  `json:"b"  msgpack:"b"`
	TransactionId string `json:"tx" msgpack:"tx"`
}

// BeginParams is the body of a Begin command (the `dt`).
// PHP: SConcur\Features\Sql\Payloads\BeginPayload.
type BeginParams struct {
	IsolationLevel int  `json:"iso" msgpack:"iso"`
	ReadOnly       bool `json:"ro"  msgpack:"ro"`
}

// TransactionRefParams is the body of a Commit/Rollback command (the `dt`).
// PHP: SConcur\Features\Sql\Payloads\CommitPayload, RollbackPayload.
type TransactionRefParams struct {
	TransactionId string `json:"tx" msgpack:"tx"`
}

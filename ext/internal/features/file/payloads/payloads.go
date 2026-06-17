// Package payloads holds the Go counterparts of the PHP File payload objects
// (SConcur\Features\File\Payloads\*). Struct tags are the short keys emitted by the
// PHP getData()/getCommandData() methods; the Go side decodes by msgpack tag.
package payloads

import "github.com/vmihailenco/msgpack/v5"

// Envelope wraps every File command: the sub-operation, the per-command execution
// time limit and the command body. PHP: SConcur\Features\File\Payloads\Base\BaseFilePayload.
type Envelope struct {
	Command   int                `json:"cm" msgpack:"cm"`
	TimeoutMs int                `json:"to" msgpack:"to"`
	Data      msgpack.RawMessage `json:"dt" msgpack:"dt"`
}

// OpenParams is the body of an Open command (the `dt`). The mode is the validated
// fopen-style string (r, r+, w, w+, a, a+, x, x+, c, c+); Go maps it to os flags.
// PHP: SConcur\Features\File\Payloads\OpenPayload.
type OpenParams struct {
	Path string `json:"p"  msgpack:"p"`
	Mode string `json:"md" msgpack:"md"`
	Perm int    `json:"pm" msgpack:"pm"`
}

// ReadParams is the body of a Read command (the `dt`).
// PHP: SConcur\Features\File\Payloads\ReadPayload.
type ReadParams struct {
	HandleId string `json:"h" msgpack:"h"`
	Length   int    `json:"n" msgpack:"n"`
}

// WriteParams is the body of a Write command (the `dt`).
// PHP: SConcur\Features\File\Payloads\WritePayload.
type WriteParams struct {
	HandleId string `json:"h" msgpack:"h"`
	Bytes    string `json:"b" msgpack:"b"`
}

// SeekParams is the body of a Seek command (the `dt`). Whence mirrors PHP/Go:
// 0 = SEEK_SET, 1 = SEEK_CUR, 2 = SEEK_END.
// PHP: SConcur\Features\File\Payloads\SeekPayload.
type SeekParams struct {
	HandleId string `json:"h" msgpack:"h"`
	Offset   int64  `json:"o" msgpack:"o"`
	Whence   int    `json:"w" msgpack:"w"`
}

// TruncateParams is the body of a Truncate command (the `dt`).
// PHP: SConcur\Features\File\Payloads\TruncatePayload.
type TruncateParams struct {
	HandleId string `json:"h" msgpack:"h"`
	Size     int64  `json:"s" msgpack:"s"`
}

// HandleRefParams is the body of a Sync/Stat/Close command (the `dt`): just the
// handle reference.
// PHP: SConcur\Features\File\Payloads\SyncPayload, StatPayload, ClosePayload.
type HandleRefParams struct {
	HandleId string `json:"h" msgpack:"h"`
}

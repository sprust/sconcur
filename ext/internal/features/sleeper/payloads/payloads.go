// Package payloads holds the Go counterparts of the PHP Sleeper payload objects
// (SConcur\Features\Sleeper\Payloads\*). The struct tags are the short keys emitted by
// the PHP getData() methods.
package payloads

// SleeperPayload is the payload of a sleep command.
// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
type SleeperPayload struct {
	Microseconds int64 `json:"us" msgpack:"us"`
}

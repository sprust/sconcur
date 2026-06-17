package file_feature

import (
	"os"
	"sconcur/internal/dto"
	"sconcur/internal/helpers"
	"sync"
	"time"
)

// pendingFiles maps a handle id (the open task key) to its live session, so a
// read/write/seek/close command arriving on its own task finds the pinned
// *os.File. Mirrors the SQL feature's pendingTransactions.
var pendingFiles sync.Map

// fileSession ties an open *os.File to its handle id. close runs exactly once (the
// explicit Close command or the holder's cleanup), so the descriptor is released a
// single time regardless of which path ends the handle. The mutex serializes every
// sub-operation against close (close may fire from context cancellation while a
// read/write is still using the file).
type fileSession struct {
	mutex    sync.Mutex
	file     *os.File
	path     string
	id       string
	finalize sync.Once
}

// close finalizes the handle: removes it from the registry and closes the
// descriptor. Idempotent — a second call (e.g. an explicit Close racing a flow
// stop) is a no-op.
func (s *fileSession) close() error {
	var err error

	s.finalize.Do(func() {
		s.mutex.Lock()
		defer s.mutex.Unlock()

		pendingFiles.Delete(s.id)

		err = s.file.Close()
	})

	return err
}

// fileHolderState keeps the open task alive (registered with hasNext) so the pinned
// descriptor survives across the handle's commands. Its Next is the release marker
// pulled by PHP after Close; Close closes the descriptor as a safety net (a no-op
// once the handle was already finalized).
type fileHolderState struct {
	session   *fileSession
	message   *dto.Message
	startTime time.Time
}

func (h *fileHolderState) Next() *dto.Result {
	return dto.NewSuccessResult(h.message, "", helpers.CalcExecutionMs(h.startTime))
}

func (h *fileHolderState) Close() {
	_ = h.session.close()
}

// loadSession returns the live session for a command carrying a handle id, or an
// error string if it is not found.
func (f *FileFeature) loadSession(handleId string) (*fileSession, string) {
	value, ok := pendingFiles.Load(handleId)

	if !ok {
		return nil, f.errFactory.ByText("unknown file handle " + handleId)
	}

	session, ok := value.(*fileSession)

	if !ok {
		return nil, f.errFactory.ByText("bad file session")
	}

	return session, ""
}

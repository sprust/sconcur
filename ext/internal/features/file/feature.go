package file_feature

import (
	"context"
	"io"
	"os"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/file/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*FileFeature)(nil)

var once sync.Once
var instance *FileFeature

// defaultPerm is the file mode used when the PHP side does not pass an explicit
// perm (mirrors PHP's 0644 default for fopen-created files).
const defaultPerm os.FileMode = 0644

// FileFeature handles File commands on top of os.File. A handle is opened by the
// Open command (kept alive by a held task, like an SQL transaction's begin) and
// every later read/write/seek/close carries the handle id, routed to the pinned
// descriptor via pendingFiles.
type FileFeature struct {
	errFactory *errs.Factory
}

func Get() *FileFeature {
	once.Do(func() {
		instance = &FileFeature{
			errFactory: errs.NewErrorsFactory("file"),
		}
	})

	return instance
}

func (f *FileFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse envelope", err)))

		return
	}

	switch types.FileCommand(envelope.Command) {
	case types.FileOpen:
		f.handleOpen(task, &envelope)
	case types.FileRead:
		f.handleRead(task, &envelope)
	case types.FileWrite:
		f.handleWrite(task, &envelope)
	case types.FileSeek:
		f.handleSeek(task, &envelope)
	case types.FileTruncate:
		f.handleTruncate(task, &envelope)
	case types.FileSync:
		f.handleSync(task, &envelope)
	case types.FileStat:
		f.handleStat(task, &envelope)
	case types.FileClose:
		f.handleClose(task, &envelope)
	default:
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("unknown command")))
	}
}

// handleOpen opens the file and registers the holder state. The result carries
// hasNext so the open task's context stays alive for the whole handle; when that
// context is cancelled (flow stop), the holder's Close closes the descriptor.
func (f *FileFeature) handleOpen(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.OpenParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse open params", err)))

		return
	}

	flags, ok := modeToFlags(params.Mode)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("invalid file mode "+params.Mode)))

		return
	}

	perm := os.FileMode(params.Perm)

	if perm == 0 {
		perm = defaultPerm
	}

	file, err := os.OpenFile(params.Path, flags, perm)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("open", err)))

		return
	}

	handleId := message.TaskKey

	session := &fileSession{
		file: file,
		path: params.Path,
		id:   handleId,
	}

	if _, loaded := pendingFiles.LoadOrStore(handleId, session); loaded {
		_ = file.Close()

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("duplicate file handle "+handleId)))

		return
	}

	holder := &fileHolderState{
		session:   session,
		message:   message,
		startTime: startTime,
	}

	if err := states.Get().Register(message.TaskKey, holder); err != nil {
		_ = session.close()

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("register file handle", err)))

		return
	}

	// On flow stop / parent cancellation: drop the holder, which closes the
	// descriptor (if not already finalized).
	context.AfterFunc(task.GetContext(), func() {
		states.Get().DeleteState(handleId)
	})

	task.AddResult(dto.NewSuccessResultWithNext(message, "", helpers.CalcExecutionMs(startTime)))
}

// handleRead reads up to Length bytes from the current position and returns the
// bytes, an eof flag and the new position.
func (f *FileFeature) handleRead(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.ReadParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse read params", err)))

		return
	}

	if params.Length <= 0 {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("read length must be positive")))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	result := f.runWithTimeout(task, envelope.TimeoutMs, func() *dto.Result {
		session.mutex.Lock()
		defer session.mutex.Unlock()

		data, eof, err := readUpTo(session.file, params.Length)

		if err != nil {
			return dto.NewErrorResult(message, f.errFactory.ByErr("read", err))
		}

		offset, _ := session.file.Seek(0, io.SeekCurrent)

		payload, marshalErr := msgpack.Marshal(map[string]any{
			"b": string(data),
			"e": eof,
			"p": offset,
		})

		if marshalErr != nil {
			return dto.NewErrorResult(message, f.errFactory.ByErr("marshal read result", marshalErr))
		}

		return dto.NewSuccessResult(message, string(payload), helpers.CalcExecutionMs(startTime))
	})

	task.AddResult(result)
}

// handleWrite writes the bytes at the current position and returns the number of
// bytes written and the new position (the end of the file in append mode).
func (f *FileFeature) handleWrite(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.WriteParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse write params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	result := f.runWithTimeout(task, envelope.TimeoutMs, func() *dto.Result {
		session.mutex.Lock()
		defer session.mutex.Unlock()

		written, err := session.file.Write([]byte(params.Bytes))

		if err != nil {
			return dto.NewErrorResult(message, f.errFactory.ByErr("write", err))
		}

		offset, _ := session.file.Seek(0, io.SeekCurrent)

		payload, marshalErr := msgpack.Marshal(map[string]any{
			"n": written,
			"p": offset,
		})

		if marshalErr != nil {
			return dto.NewErrorResult(message, f.errFactory.ByErr("marshal write result", marshalErr))
		}

		return dto.NewSuccessResult(message, string(payload), helpers.CalcExecutionMs(startTime))
	})

	task.AddResult(result)
}

// handleSeek repositions the descriptor and returns the new absolute position.
func (f *FileFeature) handleSeek(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.SeekParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse seek params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	session.mutex.Lock()

	offset, err := session.file.Seek(params.Offset, params.Whence)

	session.mutex.Unlock()

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("seek", err)))

		return
	}

	payload, marshalErr := msgpack.Marshal(map[string]any{"p": offset})

	if marshalErr != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("marshal seek result", marshalErr)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, string(payload), helpers.CalcExecutionMs(startTime)))
}

// handleTruncate resizes the file to Size bytes.
func (f *FileFeature) handleTruncate(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.TruncateParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse truncate params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	session.mutex.Lock()

	err := session.file.Truncate(params.Size)

	session.mutex.Unlock()

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("truncate", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// handleSync flushes the file to stable storage (fsync).
func (f *FileFeature) handleSync(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.HandleRefParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse sync params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	result := f.runWithTimeout(task, envelope.TimeoutMs, func() *dto.Result {
		session.mutex.Lock()
		defer session.mutex.Unlock()

		if err := session.file.Sync(); err != nil {
			return dto.NewErrorResult(message, f.errFactory.ByErr("sync", err))
		}

		return dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime))
	})

	task.AddResult(result)
}

// handleStat returns the file size, modification time (ms) and mode bits.
func (f *FileFeature) handleStat(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.HandleRefParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse stat params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	session.mutex.Lock()

	info, err := session.file.Stat()

	session.mutex.Unlock()

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("stat", err)))

		return
	}

	payload, marshalErr := msgpack.Marshal(map[string]any{
		"sz": info.Size(),
		"mt": info.ModTime().UnixMilli(),
		"md": uint32(info.Mode()),
	})

	if marshalErr != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("marshal stat result", marshalErr)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, string(payload), helpers.CalcExecutionMs(startTime)))
}

// handleClose closes the descriptor and forgets the session; PHP then releases the
// held open task via next(). close is idempotent, so a stop racing the explicit
// call cannot double-close the descriptor.
func (f *FileFeature) handleClose(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.HandleRefParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse close params", err)))

		return
	}

	session, errText := f.loadSession(params.HandleId)

	if errText != "" {
		task.AddResult(dto.NewErrorResult(message, errText))

		return
	}

	if err := session.close(); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("close", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// runWithTimeout bounds a potentially blocking operation (read/write/sync) by the
// per-command deadline: os.File operations do not take a context, so the work runs
// in a goroutine and is raced against the timeout. A truly stuck descriptor is
// freed when the handle is closed (holder Close on flow stop).
func (f *FileFeature) runWithTimeout(task *tasks.Task, timeoutMs int, run func() *dto.Result) *dto.Result {
	if timeoutMs <= 0 {
		return run()
	}

	ctx, cancel := context.WithTimeout(task.GetContext(), time.Duration(timeoutMs)*time.Millisecond)
	defer cancel()

	done := make(chan *dto.Result, 1)

	go func() {
		done <- run()
	}()

	select {
	case result := <-done:
		return result
	case <-ctx.Done():
		return dto.NewErrorResult(task.GetMessage(), f.errFactory.ByErr("operation timed out", ctx.Err()))
	}
}

// readUpTo reads up to length bytes, looping until the buffer is full or EOF. eof
// is true once the descriptor reports io.EOF, so a single read past the end is
// reported like PHP's feof.
func readUpTo(file *os.File, length int) ([]byte, bool, error) {
	buffer := make([]byte, length)
	total := 0

	for total < length {
		read, err := file.Read(buffer[total:])

		total += read

		if err == io.EOF {
			return buffer[:total], true, nil
		}

		if err != nil {
			return nil, false, err
		}

		if read == 0 {
			break
		}
	}

	return buffer[:total], false, nil
}

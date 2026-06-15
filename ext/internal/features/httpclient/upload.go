package httpclient_feature

import (
	"context"
	"io"
	"net/http"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// pendingUploads maps a requestId to its in-flight upload session, so an upload
// chunk command (arriving on its own task/flow) finds the open request's pipe.
var pendingUploads sync.Map

// doResult is the outcome of the background client.Do for a streamed request.
type doResult struct {
	resp *http.Response
	err  error
}

// uploadSession ties a streamed request body to its in-flight client.Do: PHP
// pushes body chunks into writer (read by the request), and the Do goroutine
// publishes its result once, broadcast by closing resultReady (so the response
// state and a failed upload write can both read it).
type uploadSession struct {
	writer      *io.PipeWriter
	resultReady chan struct{}
	result      *doResult
}

// startStreamedRequest kicks off client.Do in the background with the request body
// fed by a pipe, registers the deferred response state and the upload session, and
// acks PHP that the body may now be streamed in.
func (f *HttpClientFeature) startStreamedRequest(
	task *tasks.Task,
	ctx context.Context,
	client *http.Client,
	request *http.Request,
	payload payloads.RequestParams,
	pipeWriter *io.PipeWriter,
	chunkSize int,
) {
	message := task.GetMessage()
	startTime := time.Now()

	session := &uploadSession{
		writer:      pipeWriter,
		resultReady: make(chan struct{}),
	}

	// client.Do blocks until the whole body is sent and the response headers are
	// read, so it runs in the background while PHP streams the body in.
	go func() {
		resp, err := client.Do(request)

		session.result = &doResult{resp: resp, err: err}

		close(session.resultReady)
	}()

	pendingUploads.Store(payload.RequestId, session)

	state := newDeferredResponseState(message, session, chunkSize, payload.MaxResponseBody)

	// Register without auto-reading the first batch: the response is pulled later,
	// after the body has been streamed in (client.Do is still in flight).
	if err := states.Get().Register(message.TaskKey, state); err != nil {
		pendingUploads.Delete(payload.RequestId)

		_ = pipeWriter.CloseWithError(err)

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("register request", err)))

		return
	}

	// On flow stop / deadline: drop the state, forget the session and unblock any
	// upload write still waiting on the pipe.
	context.AfterFunc(ctx, func() {
		states.Get().DeleteState(message.TaskKey)
		pendingUploads.Delete(payload.RequestId)
		_ = pipeWriter.CloseWithError(context.Canceled)
	})

	// Ack: the request is open, PHP may stream the body. HasNext keeps the state
	// alive so the response can be pulled afterwards.
	task.AddResult(dto.NewSuccessResultWithNext(message, "", helpers.CalcExecutionMs(startTime)))
}

// handleUpload applies one streamed-body write: append a chunk (blocking until the
// request consumes it — backpressure) or, when isEnd, close the body. Routed to the
// open request by its requestId.
func (f *HttpClientFeature) handleUpload(task *tasks.Task, raw msgpack.RawMessage, isEnd bool) {
	message := task.GetMessage()
	startTime := time.Now()

	var payload payloads.UploadParams

	if err := msgpack.Unmarshal(raw, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse upload params", err)))

		return
	}

	value, ok := pendingUploads.Load(payload.RequestId)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown upload "+payload.RequestId)))

		return
	}

	session, ok := value.(*uploadSession)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad upload session")))

		return
	}

	if isEnd {
		_ = session.writer.Close()

		pendingUploads.Delete(payload.RequestId)

		task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))

		return
	}

	if _, err := session.writer.Write([]byte(payload.Body)); err != nil {
		// The request side closed the pipe — client.Do failed. Surface the real
		// cause (network-class) rather than the generic closed-pipe error.
		task.AddResult(dto.NewErrorResult(message, uploadWriteError(session, err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// uploadWriteError resolves the real reason a body write failed: the pipe only
// breaks when client.Do has unwound, so its result is (or is about to be) ready.
func uploadWriteError(session *uploadSession, writeErr error) string {
	<-session.resultReady

	if session.result.err != nil {
		return networkErrorPayload(session.result.err.Error())
	}

	return networkErrorPayload(writeErr.Error())
}

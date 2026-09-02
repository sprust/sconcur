package httpclient_feature

import (
	"io"
	"net/http"
	"os"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// defaultDownloadBufferSizeBytes is the io.CopyBuffer size used when PHP does not
// pass an explicit one. 64 KiB mirrors the response read granularity.
const defaultDownloadBufferSizeBytes = 64 << 10

// Download modes, mirrored from PHP DownloadFileMode.
const (
	downloadModeReplace = "rpl" // create or truncate
	downloadModeCreate  = "crt" // create, fail if exists
	downloadModeAppend  = "app" // create or append
)

// downloadMeta is the single result of a download: the response status, the raw
// response headers (as the server returned them) and the number of bytes written to
// the file (the authoritative size — io.Copy ground truth, independent of any
// Content-Length header).
// PHP: decoded in SConcur\Features\HttpClient\HttpClient::download.
type downloadMeta struct {
	Status  int                 `msgpack:"st"`
	Headers map[string][]string `msgpack:"hd"`
	Written int64               `msgpack:"n"`
}

// downloadModeToFlags maps a DownloadFileMode to os.OpenFile flags — the single
// source of those platform constants on the Go side.
func downloadModeToFlags(mode string) (int, bool) {
	switch mode {
	case downloadModeReplace:
		return os.O_WRONLY | os.O_CREATE | os.O_TRUNC, true
	case downloadModeCreate:
		return os.O_WRONLY | os.O_CREATE | os.O_EXCL, true
	case downloadModeAppend:
		return os.O_WRONLY | os.O_CREATE | os.O_APPEND, true
	default:
		return 0, false
	}
}

// handleDownload performs the request and copies the response body straight into a
// file (io.CopyBuffer) — the body never crosses into PHP. Only a 2xx response is
// written; a non-2xx returns its status (PHP turns it into a DownloadException)
// without touching the file. The result is the status + headers; the size is the
// Content-Length header. The request context (with the request deadline) bounds the
// whole copy, so a flow stop or timeout aborts it.
func (f *HttpClientFeature) handleDownload(
	task *tasks.Task,
	client *http.Client,
	request *http.Request,
	payload *payloads.RequestParams,
) {
	message := task.GetMessage()
	startTime := time.Now()

	flags, ok := downloadModeToFlags(payload.SinkMode)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, requestErrorPayload(errFactory.ByText("invalid sink mode"))))

		return
	}

	resp, err := client.Do(request)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, networkErrorPayload(err.Error())))

		return
	}

	defer resp.Body.Close()

	// Non-2xx: leave the file untouched (don't create/truncate). PHP raises a
	// DownloadException carrying the status.
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		task.AddResult(downloadResult(message, resp, 0, startTime))

		return
	}

	perm := os.FileMode(payload.SinkPerm)

	if perm == 0 {
		perm = 0644
	}

	file, err := os.OpenFile(payload.SinkPath, flags, perm)

	if err != nil {
		// e.g. Create mode and the file already exists (O_EXCL).
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("open sink", err)))

		return
	}

	bufferSize := payload.DownloadBufferSizeBytes

	if bufferSize <= 0 {
		bufferSize = defaultDownloadBufferSizeBytes
	}

	written, copyErr := io.CopyBuffer(file, resp.Body, make([]byte, bufferSize))

	closeErr := file.Close()

	if copyErr != nil {
		// Drop the partial file for create/replace (append can't be safely undone).
		if payload.SinkMode != downloadModeAppend {
			_ = os.Remove(payload.SinkPath)
		}

		task.AddResult(dto.NewErrorResult(message, networkErrorPayload(copyErr.Error())))

		return
	}

	if closeErr != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("close sink", closeErr)))

		return
	}

	task.AddResult(downloadResult(message, resp, written, startTime))
}

// downloadResult builds the status+headers+size result emitted once a download
// finishes (or a non-2xx response is seen, with written = 0).
func downloadResult(message *dto.Message, resp *http.Response, written int64, startTime time.Time) *dto.Result {
	serialized, err := msgpack.Marshal(downloadMeta{
		Status:  resp.StatusCode,
		Headers: resp.Header,
		Written: written,
	})

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr("marshal download result", err))
	}

	return dto.NewSuccessResult(message, string(serialized), helpers.CalcExecutionMs(startTime))
}

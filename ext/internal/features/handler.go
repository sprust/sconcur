package features

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb_feature"
	"sconcur/internal/features/mongodb_feature/connections"
	"sconcur/internal/features/sleep_feature"
	"sconcur/internal/types"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

type Handler struct {
	ctx      context.Context
	cancel   context.CancelFunc
	mutex    sync.Mutex
	finTasks chan *dto.Result
	closing  atomic.Bool
	index    int64
}

func NewHandler() *Handler {
	ctx, cancel := context.WithCancel(context.Background())

	return &Handler{
		ctx:      ctx,
		cancel:   cancel,
		finTasks: make(chan *dto.Result),
	}
}

func (h *Handler) Push(msg *dto.Message) error {
	handler, err := h.detectHandler(msg.Method)

	if err != nil {
		return err
	}

	go func() {
		h.finTasks <- handler.Handle(h.ctx, msg)
	}()

	return nil
}

func (h *Handler) Wait(timeoutMs int64) (string, error) {
	timer := time.NewTimer(time.Duration(timeoutMs) * time.Millisecond)
	defer timer.Stop()

	select {
	case res, ok := <-h.finTasks:
		if !ok {
			return "", errors.New("task channel closed")
		}

		b, err := json.Marshal(res)

		if err != nil {
			return "", err
		}

		return string(b), nil
	case <-timer.C:
		return "", errors.New("timeout waiting for task completion")
	case <-h.ctx.Done():
		return "", h.ctx.Err()
	}
}

func (h *Handler) detectHandler(method types.Method) (contracts.MessageHandler, error) {
	if method == 1 {
		return sleep_feature.New(), nil
	}

	if method == 2 {
		return mongodb_feature.New(
			h.finTasks,
			connections.GetConnections(),
		), nil
	}

	return nil, errors.New("unknown method: " + fmt.Sprint(method))
}

func (h *Handler) genTaskId() types.TaskId {
	h.index += 1

	return types.TaskId(strconv.FormatInt(h.index, 10))
}

func (h *Handler) Stop() {
	h.cancel()
	close(h.finTasks)
}

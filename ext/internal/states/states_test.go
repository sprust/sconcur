package states

import (
	"context"
	"sync"
	"testing"
	"time"

	"sconcur/internal/dto"
)

type stubState struct {
	mutex   sync.Mutex
	message *dto.Message
	closed  int
}

func (s *stubState) Next() *dto.Result {
	return dto.NewSuccessResultWithNext(s.message, "", 0)
}

func (s *stubState) Close() {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	s.closed++
}

func (s *stubState) Closed() int {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	return s.closed
}

func TestDeleteStateClosesState(t *testing.T) {
	taskKey := "states-test-delete"

	stub := &stubState{message: &dto.Message{TaskKey: taskKey}}

	result, err := Get().Start(context.Background(), taskKey, stub)

	if err != nil {
		t.Fatal(err)
	}

	if !result.HasNext {
		t.Fatal("expected state to stay registered")
	}

	Get().DeleteState(taskKey)

	if stub.Closed() == 0 {
		t.Fatal("DeleteState must close the state")
	}
}

func TestContextCancellationClosesState(t *testing.T) {
	taskKey := "states-test-cancel"

	stub := &stubState{message: &dto.Message{TaskKey: taskKey}}

	ctx, ctxCancel := context.WithCancel(context.Background())

	if _, err := Get().Start(ctx, taskKey, stub); err != nil {
		ctxCancel()
		t.Fatal(err)
	}

	ctxCancel()

	deadline := time.Now().Add(time.Second)

	for time.Now().Before(deadline) {
		if stub.Closed() > 0 {
			return
		}

		time.Sleep(time.Millisecond)
	}

	t.Fatal("cancelling the task context must close the state")
}

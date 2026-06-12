package flows

import (
	"context"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/tasks"
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

func TestWaitCancelsTaskContextAfterDelivery(t *testing.T) {
	flow := NewFlow(context.Background(), "flow")

	payload, err := msgpack.Marshal(map[string]int64{"ms": 1})

	if err != nil {
		t.Fatal(err)
	}

	msg := &dto.Message{
		FlowKey: "flow",
		Method:  types.Method(1), // sleep
		TaskKey: "task-1",
		Payload: payload,
	}

	if err := flow.HandleMessage(msg); err != nil {
		t.Fatal(err)
	}

	flow.mutex.Lock()
	task := flow.activeTasks[msg.TaskKey]
	flow.mutex.Unlock()

	if task == nil {
		t.Fatal("task not registered")
	}

	result, err := flow.Wait()

	if err != nil {
		t.Fatal(err)
	}

	if result.IsError {
		t.Fatalf("unexpected error result: %s", result.Payload)
	}

	select {
	case <-task.GetContext().Done():
	case <-time.After(time.Second):
		t.Fatal("task context was not cancelled after result delivery")
	}

	if flow.Count() != 0 {
		t.Fatalf("expected zero active tasks, got %d", flow.Count())
	}
}

func TestHandleMessageUnknownMethodLeavesFlowStateUntouched(t *testing.T) {
	flow := NewFlow(context.Background(), "flow")

	msg := &dto.Message{
		FlowKey: "flow",
		Method:  types.Method(99),
		TaskKey: "task-1",
	}

	if err := flow.HandleMessage(msg); err == nil {
		t.Fatal("expected an error for an unknown method")
	}

	if flow.Count() != 0 {
		t.Fatalf("a task that never runs must not be counted, got %d", flow.Count())
	}

	flow.mutex.Lock()
	_, registered := flow.activeTasks[msg.TaskKey]
	flow.mutex.Unlock()

	if registered {
		t.Fatal("a task that never runs must not be registered")
	}
}

func TestWaitKeepsInitialTaskContextWhileHasNext(t *testing.T) {
	flow := NewFlow(context.Background(), "flow")

	msg := &dto.Message{
		FlowKey: "flow",
		TaskKey: "task-1",
	}

	task := tasks.NewTask(flow.ctx, flow.results, msg)

	flow.mutex.Lock()
	flow.activeTasks[msg.TaskKey] = task
	flow.tasksCount.Add(1)
	flow.mutex.Unlock()

	go task.AddResult(dto.NewSuccessResultWithNext(msg, "", 0))

	if _, err := flow.Wait(); err != nil {
		t.Fatal(err)
	}

	select {
	case <-task.GetContext().Done():
		t.Fatal("initial task context owns the cursor state and must stay alive while batches remain")
	default:
	}
}

func TestWaitCancelsNextTaskContextEvenWithNext(t *testing.T) {
	flow := NewFlow(context.Background(), "flow")

	msg := &dto.Message{
		FlowKey: "flow",
		TaskKey: "task-1",
		IsNext:  true,
	}

	task := tasks.NewTask(flow.ctx, flow.results, msg)

	flow.mutex.Lock()
	flow.activeTasks[msg.TaskKey] = task
	flow.tasksCount.Add(1)
	flow.mutex.Unlock()

	go task.AddResult(dto.NewSuccessResultWithNext(msg, "", 0))

	if _, err := flow.Wait(); err != nil {
		t.Fatal(err)
	}

	select {
	case <-task.GetContext().Done():
	case <-time.After(time.Second):
		t.Fatal("next-task context does not own the cursor state and must be cancelled after delivery")
	}
}
